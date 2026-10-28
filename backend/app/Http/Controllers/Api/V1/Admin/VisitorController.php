<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\VisitorResource;
use App\Http\Responses\ApiResponse;
use App\Models\Appointment;
use App\Models\Staff;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Notifications\NotificationService;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Front Office > Visitors: the visitor log. Walk-ins are recorded here
 * directly (checked in now); appointments arrive through Check in on the
 * Appointments tab. Check out stamps the time and completes the appointment.
 */
class VisitorController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Visitor::class);

        $query = Visitor::query()->with('host');
        if ($request->boolean('on_site')) {
            $query->onSite();
        }
        if ($date = $request->query('date')) {
            $request->validate(['date' => ['date_format:Y-m-d']]);
            $query->whereDate('check_in_at', $date);
        }

        $visitors = ApiQuery::for($query, $request)
            ->searchable('name', 'phone', 'organization', 'purpose', 'badge_no')
            ->filterable(['host_staff_id'])
            ->sortable(['check_in_at', 'check_out_at', 'name'], default: '-check_in_at')
            ->paginate();

        return ApiResponse::success(VisitorResource::collection($visitors));
    }

    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', Visitor::class);

        $today = now()->toDateString();

        return ApiResponse::success([
            'today' => Visitor::query()->whereDate('check_in_at', $today)->count(),
            'people_today' => (int) Visitor::query()->whereDate('check_in_at', $today)->sum('people_count'),
            'on_site' => Visitor::query()->onSite()->count(),
            'this_month' => Visitor::query()->whereBetween('check_in_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
        ]);
    }

    /**
     * Active staff, for "Meeting with" on appointments and visitors (front
     * desk users may not see the Staff list).
     */
    public function staffOptions(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->hasPermission(Permissions::VISITORS_VIEW) || $request->user()?->hasPermission(Permissions::APPOINTMENTS_VIEW),
            403,
        );

        $staff = Staff::query()->active()->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'employee_code'])
            ->map(fn (Staff $s) => ['id' => $s->id, 'name' => $s->fullName(), 'employee_code' => $s->employee_code]);

        return ApiResponse::success($staff);
    }

    /** A walk-in: checked in now unless a time is given. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Visitor::class);

        $data = $this->validated($request, true);
        $visitor = Visitor::query()->create([
            ...$data,
            'people_count' => $data['people_count'] ?? 1,
            'check_in_at' => $data['check_in_at'] ?? now(),
            'created_by' => $request->user()?->id,
        ]);
        self::notifyArrival($this->notifications, $visitor, $request->user());

        return ApiResponse::created(new VisitorResource($visitor->load('host', 'appointment')));
    }

    public function show(Visitor $visitor): JsonResponse
    {
        $this->authorize('view', $visitor);

        return ApiResponse::success(new VisitorResource($visitor->load('host', 'appointment')));
    }

    public function update(Request $request, Visitor $visitor): JsonResponse
    {
        $this->authorize('update', $visitor);

        $data = $this->validated($request, false);
        $checkIn = $data['check_in_at'] ?? $visitor->check_in_at;
        $checkOut = array_key_exists('check_out_at', $data) ? $data['check_out_at'] : $visitor->check_out_at;
        if ($checkOut !== null && strtotime((string) $checkOut) < strtotime((string) $checkIn)) {
            throw ValidationException::withMessages(['check_out_at' => 'Check-out cannot be before check-in.']);
        }

        DB::connection('tenant')->transaction(function () use ($visitor, $data) {
            $visitor->update($data);
            $this->syncAppointment($visitor);
        });

        return ApiResponse::success(new VisitorResource($visitor->load('host', 'appointment')));
    }

    /** They left: stamp the time; a visit from an appointment completes it. */
    public function checkOut(Visitor $visitor): JsonResponse
    {
        $this->authorize('update', $visitor);

        if ($visitor->check_out_at !== null) {
            throw ValidationException::withMessages(['check_out_at' => 'Already checked out.']);
        }

        DB::connection('tenant')->transaction(function () use ($visitor) {
            $visitor->update(['check_out_at' => now()]);
            $this->syncAppointment($visitor);
        });

        return ApiResponse::success(new VisitorResource($visitor->load('host', 'appointment')));
    }

    /** Deleting a checked-in appointment's visit puts the appointment back to scheduled. */
    public function destroy(Visitor $visitor): JsonResponse
    {
        $this->authorize('delete', $visitor);

        DB::connection('tenant')->transaction(function () use ($visitor) {
            if ($visitor->appointment_id) {
                Appointment::query()->whereKey($visitor->appointment_id)->whereIn('status', ['checked_in', 'completed'])->update(['status' => 'scheduled']);
            }
            $visitor->delete();
        });

        return ApiResponse::noContent();
    }

    /** Tell the staff member being visited that their visitor is here. */
    public static function notifyArrival(NotificationService $notifications, Visitor $visitor, ?User $actor): void
    {
        $user = $visitor->host_staff_id ? Staff::query()->find($visitor->host_staff_id)?->user : null;
        if ($user === null || ($actor !== null && $user->getKey() === $actor->getKey())) {
            return;
        }

        $notifications->notifyMany([$user], NotificationType::VISITOR_ARRIVED, [
            'visitor_id' => $visitor->id,
            'name' => $visitor->name,
            'purpose' => $visitor->purpose,
            'time' => $visitor->check_in_at->format('H:i'),
        ], link: '/admin/front-office/visitors');
    }

    private function syncAppointment(Visitor $visitor): void
    {
        if (! $visitor->appointment_id) {
            return;
        }
        Appointment::query()->whereKey($visitor->appointment_id)->whereIn('status', ['checked_in', 'completed'])
            ->update(['status' => $visitor->check_out_at === null ? 'checked_in' : 'completed']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'id_number' => ['nullable', 'string', 'max:50'],
            'organization' => ['nullable', 'string', 'max:150'],
            'purpose' => [$required, 'string', 'max:255'],
            'host_staff_id' => ['nullable', 'integer', Rule::exists('tenant.staff', 'id')->whereNull('deleted_at')],
            'people_count' => ['nullable', 'integer', 'min:1', 'max:500'],
            'badge_no' => ['nullable', 'string', 'max:30'],
            'check_in_at' => ['sometimes', 'date'],
            'check_out_at' => [$creating ? 'prohibited' : 'sometimes', 'nullable', 'date'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
