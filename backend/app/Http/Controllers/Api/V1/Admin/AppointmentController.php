<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\VisitorResource;
use App\Http\Responses\ApiResponse;
use App\Models\Appointment;
use App\Models\Staff;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Notifications\NotificationService;
use App\Support\Notifications\NotificationType;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Front Office > Appointments: book a visit with a staff member (who is
 * notified), then Check in when the person arrives — that creates their
 * Visitor record — or mark it cancelled / no-show.
 */
class AppointmentController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);

        $query = Appointment::query()->with('host', 'visitor');
        $today = now()->toDateString();
        $when = $request->query('when');
        if ($when === 'upcoming') {
            $query->where('appointment_date', '>=', $today)->where('status', 'scheduled');
        } elseif ($when === 'today') {
            $query->whereDate('appointment_date', $today);
        } elseif ($when === 'past') {
            $query->where(fn ($q) => $q->where('appointment_date', '<', $today)->orWhere('status', '!=', 'scheduled'));
        }

        // Soonest first for what is coming; newest first otherwise. Same day: by time.
        if (! $request->filled('sort')) {
            $request->merge(['sort' => in_array($when, ['upcoming', 'today'], true) ? 'appointment_date,start_time' : '-appointment_date,-start_time']);
        }
        $appointments = ApiQuery::for($query, $request)
            ->searchable('visitor_name', 'phone', 'organization', 'purpose')
            ->filterable(['status', 'host_staff_id'])
            ->sortable(['appointment_date', 'start_time', 'visitor_name', 'created_at'])
            ->paginate();

        return ApiResponse::success(AppointmentResource::collection($appointments));
    }

    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);

        $today = now()->toDateString();

        return ApiResponse::success([
            'today' => Appointment::query()->whereDate('appointment_date', $today)->whereIn('status', ['scheduled', 'checked_in', 'completed'])->count(),
            'waiting' => Appointment::query()->whereDate('appointment_date', $today)->where('status', 'scheduled')->count(),
            'upcoming' => Appointment::query()->where('appointment_date', '>', $today)->where('status', 'scheduled')->count(),
            'no_show' => Appointment::query()->where('status', 'no_show')->whereBetween('appointment_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Appointment::class);

        $appointment = Appointment::query()->create([...$this->validated($request, true), 'created_by' => $request->user()?->id]);
        $this->notifyHost($appointment, NotificationType::APPOINTMENT_BOOKED, $request->user());

        return ApiResponse::created(new AppointmentResource($appointment->load('host', 'visitor')));
    }

    public function show(Appointment $appointment): JsonResponse
    {
        $this->authorize('view', $appointment);

        return ApiResponse::success(new AppointmentResource($appointment->load('host', 'visitor')));
    }

    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('update', $appointment);

        $data = $this->validated($request, false);
        if (isset($data['status']) && in_array($data['status'], ['checked_in', 'completed'], true) && $data['status'] !== $appointment->status) {
            throw ValidationException::withMessages(['status' => 'Use Check in to start a visit.']);
        }

        $appointment->update($data);
        if ($appointment->wasChanged('host_staff_id') && $appointment->status === 'scheduled') {
            $this->notifyHost($appointment, NotificationType::APPOINTMENT_BOOKED, $request->user());
        }

        return ApiResponse::success(new AppointmentResource($appointment->load('host', 'visitor')));
    }

    public function destroy(Appointment $appointment): JsonResponse
    {
        $this->authorize('delete', $appointment);

        $appointment->delete();

        return ApiResponse::noContent();
    }

    /** The person arrived: create their visitor record now and tell their host. */
    public function checkIn(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('update', $appointment);
        $this->authorize('create', Visitor::class);

        if ($appointment->status !== 'scheduled') {
            throw ValidationException::withMessages(['status' => 'Only a scheduled appointment can be checked in.']);
        }

        $data = $request->validate([
            'people_count' => ['nullable', 'integer', 'min:1', 'max:500'],
            'id_number' => ['nullable', 'string', 'max:50'],
            'badge_no' => ['nullable', 'string', 'max:30'],
        ]);

        $visitor = DB::connection('tenant')->transaction(function () use ($request, $appointment, $data) {
            $visitor = Visitor::query()->create([
                'appointment_id' => $appointment->id,
                'name' => $appointment->visitor_name,
                'phone' => $appointment->phone,
                'organization' => $appointment->organization,
                'purpose' => $appointment->purpose,
                'host_staff_id' => $appointment->host_staff_id,
                'people_count' => $data['people_count'] ?? 1,
                'id_number' => $data['id_number'] ?? null,
                'badge_no' => $data['badge_no'] ?? null,
                'check_in_at' => now(),
                'created_by' => $request->user()?->id,
            ]);
            $appointment->update(['status' => 'checked_in']);

            return $visitor;
        });

        VisitorController::notifyArrival($this->notifications, $visitor, $request->user());

        return ApiResponse::created(new VisitorResource($visitor->load('host', 'appointment')));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'visitor_name' => [$required, 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'organization' => ['nullable', 'string', 'max:150'],
            'purpose' => [$required, 'string', 'max:255'],
            'appointment_date' => [$required, 'date_format:Y-m-d'],
            'start_time' => [$required, 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'host_staff_id' => ['nullable', 'integer', Rule::exists('tenant.staff', 'id')->whereNull('deleted_at')],
            'status' => [$creating ? 'prohibited' : 'sometimes', Rule::in(Appointment::STATUSES)],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function notifyHost(Appointment $appointment, string $type, ?User $actor): void
    {
        $user = $appointment->host_staff_id ? Staff::query()->find($appointment->host_staff_id)?->user : null;
        if ($user === null || ($actor !== null && $user->getKey() === $actor->getKey())) {
            return;
        }

        $this->notifications->notifyMany([$user], $type, [
            'appointment_id' => $appointment->id,
            'name' => $appointment->visitor_name,
            'date' => $appointment->appointment_date->toDateString(),
            'time' => substr((string) $appointment->start_time, 0, 5),
            'purpose' => $appointment->purpose,
        ], link: '/admin/front-office/appointments');
    }
}
