# Stage 13 — Help & Support

The top bar's chat icon becomes a **Support** button. It opens a small menu:
**Chat with NewTech** (the Stage 10 chat) and **Help & Support**, a page in
the system with help articles, a FAQ, NewTech's contacts and (13b) support
tickets. NewTech writes the articles, FAQ and contacts in the back office
and answers tickets there.

## Decisions (user, 2026-10-30)

| Question | Answer |
|---|---|
| What the page has | **Help articles, FAQ, Support tickets, Contact NewTech** |
| Who sees it | **By role**: a permission `help-support.view` in the role editor (on by default for School Admin, Teacher and Staff; never for students). The chat stays as it is (every staff user). |
| Languages | Content in **Khmer and English** (like the website); a Khmer screen shows Khmer, every other language English, each falling back to the other when empty. |

## Steps (one at a time, check in after each)

### 13a. Help articles, FAQ, Contact — ✅ live 2026-10-30
- Central tables `bo_help_articles` (module or none, title and body in en/km,
  draft/published, order) and `bo_help_faqs` (module or none, question and
  answer in en/km, draft/published, order). Contacts on `bo_support_settings`:
  phone, Telegram, email, working hours (en/km).
- Back office: Support → **Help center** tab: Articles, FAQ, Contact. New
  platform permission `platform.support.help` (write them); reading needs
  `platform.support.read`. Audited.
- Console API `api/v1/help` (needs `help-support.view`): contacts, published
  articles (only for modules on the school's plan, plus general ones) and FAQ;
  `api/v1/help/articles/{id}`.
- Console: Support button + menu; `/admin/help` with tabs Help articles (search,
  grouped by module, article page), FAQ (search, expand), Contact (call,
  Telegram, email, hours, open the chat).

### 13b. Support tickets — ✅ live 2026-10-30
- A school user sends a ticket (subject, module, priority, description,
  screenshots/files), follows its status (open → in progress → resolved →
  closed) and NewTech's replies; NewTech answers in the back office, with a
  Telegram alert on a new ticket.

## As built (13a)

- Migration `2026_10_30_040000_create_bo_help_tables` (central): the two tables,
  the contact columns, grants `help-support.view` to the School Admin, Teacher
  and Staff roles of every school, and `platform.support.help` to back office
  roles that can already answer chats.
- Back office: `HelpCenterController` under `api/v1/platform/support/help`
  (`modules`, `articles`, `faqs`, `contact`); page `pages/support/HelpTab.vue`
  and `components/HelpEntryModal.vue`. A new item with no order goes last.
- Console: `HelpController` (`GET help`, `GET help/articles/{id}`; list items
  carry a 220-character summary, not the body); `SupportChat.vue` is the
  Support button (menu only when the role has `help-support.view`, otherwise it
  opens the chat directly); pages in `pages/admin/help/`.
- Article text is plain text with light marks (`# ` heading, `- ` list item,
  blank line between paragraphs), always shown as text, never as HTML.
- `RouteTabBar` now keeps a tab lit on pages under it (an article under Help
  articles): the longest tab path that is the page or its parent wins.
- vue-i18n treats `@` as a linked message: write `{'@'}` in translations.
- Tests: `tests/Feature/Platform/HelpCenterTest.php`.

## As built (13b)

- Migration `2026_10_30_050000_create_bo_tickets_tables` (central): `bo_tickets`
  (school, user, subject, module, priority low/normal/high/urgent, status
  open/in_progress/resolved/closed, assignee, unread counts both ways,
  last reply, resolved/closed times) and `bo_ticket_messages` (replies with
  files under `tickets/{id}/` on the private disk, and status-change lines
  in `event`). Gives the new back office permission
  `platform.support.tickets` to roles that answer chats.
- Rules (`App\Platform\Support\Tickets`): a new ticket is open; NewTech's
  first reply makes it in progress and assigns it to whoever answered if
  nobody has it; NewTech sets resolved/closed, priority and assignee; the
  user may close it; a user reply on a resolved or closed ticket opens it
  again. Every status change is a line in the thread. Telegram (the chat's
  settings) on a new ticket and on each user reply (`SendTicketTelegram`),
  linking to `/support/tickets/{id}`.
- School app: `SupportTicketController` under `api/v1/support-tickets`
  (needs `help-support.view`, own tickets only, not students); Help &
  Support → **Support tickets** tab (`HelpTickets.vue`, table on desktop,
  cards on phone; Active also keeps tickets with an unseen reply),
  `HelpTicket.vue` and `components/help/TicketFormModal.vue` (Ctrl+V pastes a
  screenshot). The Support button's dot adds ticket replies to chat replies.
  In a back office support view the pages are read-only and opening a ticket
  does not mark it read.
- Back office: `TicketController` under `api/v1/platform/support/tickets`;
  Support → **Tickets** (`pages/support/TicketsTab.vue`): filters Active,
  Unread, Resolved, Closed, All, priority, Mine, search by T-number/subject/
  person/school. The Support menu badge counts waiting chats plus tickets
  with unread customer words.
- Tests: `tests/Feature/Platform/SupportTicketTest.php`.
