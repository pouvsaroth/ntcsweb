# Stage 11 — Bell notifications to Telegram groups

Every notification a school's user gets in the bell (leave requests,
approvals, payroll, expenses, registrations, job applications… 43 kinds) is
also sent to the Telegram group(s) that user belongs to.

## Decisions (user, 2026-10-21)

| Question | Answer |
|---|---|
| Where they go | **Groups**: the school makes groups, assigns users, each group has its own Telegram group |
| Which | **All bell notifications** |
| Rollout | 11a and 11b built one after the other, committed and deployed without a stop (user travelling) |

## How it works

- One bot for every school: NewTech's **@newtechkh_bot** (token in back office
  → Support → Settings, the same as the support chat alerts).
- **Linking:** the school presses *Link to a Telegram group*, adds the bot to
  its Telegram group and sends `/link CODE` there (code valid 30 min, unique).
  `platform:telegram-tick` (every minute, and *Check now* on the page) reads
  the bot's messages (`getUpdates`, offset kept in `bo_support_settings`)
  and links the group; the bot answers "✅ Linked".
- **Sending:** `NotificationService::notify()` (the one place a bell
  notification is made) calls `NotificationTelegram::forNotification()`:
  the recipient's linked groups get the text in the school's language
  (`lang/{en,km}/notification_types.php`, copied from the console's
  `notifications.types`) with a link into the system, queued
  (`SendTelegramMessage`). The same event to several members of one group is
  sent once (10-minute cache key). Never breaks the notification itself.

## As built (2026-10-21)

- Central tables `notification_groups` (tenant, name, Telegram chat/title,
  link code) and `notification_group_user`; cascade with the school/user.
- Console API `settings/notification-groups` (list, users, create, update,
  delete, link-code, check, test, unlink) — `tenant-settings.update`.
- Console page Settings → **Telegram notifications** (`pages/admin/TelegramGroups.vue`).
- Group lookups and the school's name/language are read with plain queries:
  going through the User/Tenant models there reset the request's current
  school (row-level security) for the writes after it; a job run at once
  (sync queue, tests) also resets it, so the school is put back after queuing.
- Tests: `tests/Feature/Platform/NotificationGroupsTest.php`.
