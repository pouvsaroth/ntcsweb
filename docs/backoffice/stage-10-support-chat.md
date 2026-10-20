# Stage 10 — Support chat inside the system

A customer's staff ask NewTech for help from inside the system: a chat
button at the top of every page, text with images and files. NewTech staff
answer from the back office; a Telegram message tells them a new question
arrived.

## Decisions (user, 2026-10-21)

| Question | Answer |
|---|---|
| Who can chat | **All staff users** of a customer (not students); each person has their own chat |
| Who answers | **Back office staff** (support permission) **+ a Telegram alert** to a NewTech group |
| Attachments | **Images and files** (JPG/PNG/WebP, PDF, Word, Excel), up to 10 MB each |
| Live updates | No push server today: the open chat checks every 5 s, the badge every 30 s |

## Steps (one at a time, check in after each)

### 10a. Server
- Central tables: `bo_chats` (customer, user, name/email at the time, status
  open/closed, last message at, unread for staff / for the user, staff who
  answered last) and `bo_chat_messages` (chat, from user or staff, staff,
  text, attachments: path, name, type, size).
- Files on the private disk under `chats/{chat}/`; downloaded through the API
  only (the user who owns the chat, or staff with support permission).
- Console API (signed-in staff user of a customer; not students; not inside
  a back office support view): my chat, messages since an id, send (text
  and/or files), mark read.
- Back office API: chats (open / waiting for us / all, search by customer),
  one chat, reply (text/files), close / reopen, unread count. Audited.
- Telegram: Settings → Support chat: bot token (encrypted) and group chat id,
  "Send a test message". A new message from a customer (or the first after a
  reply) sends "Customer · person: text… [link to the chat]" — queued, never
  blocking the customer.
- Rate limit on sending; files checked by type and size.

**As built (2026-10-21):** tables `bo_chats` (one per user, unique) and
`bo_chat_messages`; `bo_support_settings.telegram_bot_token` (encrypted) /
`telegram_chat_id`. `App\Platform\Support\Chats` (send from user / staff,
store files on the local disk under `chats/{id}/`, present, download).
Console API `api/v1/support-chat` (show `?after=`, unread, send, read,
files) — staff users only (students and platform accounts 403; a support
view cannot send: GuardSupportView). Back office API
`platform/support/chats` (index with `filter[waiting]`, waiting count, show,
reply, close, reopen, files, settings, settings/test). New permission
`platform.support.chat` (answer, close, Telegram); reading needs
`platform.support.read`. Telegram: `SendChatTelegram` job when a question
starts (not for every line while still waiting). Customer delete removes
its chat files. Tests: `tests/Feature/Platform/SupportChatTest.php`.

### 10b. In the system (console)
- A chat button in the top bar on every page, with a red dot when NewTech
  replied. Opens a panel: the conversation, a box to type, attach (or paste a
  screenshot), send. Images show as pictures; files as links.

**As built 10b (2026-10-21):** `components/layout/SupportChat.vue` in
AdminHeader (before the bell); `services/supportChat.ts`. Hidden for
students, platform accounts and with no school; in a support view the panel
is read-only. Unread dot every 30 s; open panel polls `?after=` every 5 s and
marks read. Attach button, Ctrl+V screenshots, Enter to send.

### 10c. In the back office
- Support → **Chats**: list (customer, person, last message, waiting/answered,
  unread), the conversation on the right, reply with text and files, close.
  A badge on Support in the menu when customers are waiting.
- Settings → Support chat (Telegram).

## Done when
- `php artisan test --filter=Chat` passes (send, files, who may read what,
  students refused, support view refused, staff reply, unread, Telegram faked).
- Render check: chat panel in the console (desktop + phone), Chats in the back office.
