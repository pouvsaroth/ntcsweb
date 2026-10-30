# Accounting > Expense / Income: For month and currency

Built 2026-10-30.

## For month

The month an expense or income belongs to, which can differ from the day
the money moved: paid today (30-10-2026) for September → For month
09-2026.

- Expense form and Record Income form: a month picker. It follows the date
  until it is picked by hand.
- Expense list and Income list: a For month column and a For month filter
  (`filter[for_month]=YYYY-MM`, or plain `for_month=YYYY-MM`).
- Expense detail shows it.
- Paying an expense copies its For month to the ledger entry; a reversal
  keeps the original's month.
- Payroll expenses take the payroll's own month (period start).
- Automatic income from student payments: the month it was received (the
  user's choice).
- Older rows were filled with the month of their own date.
- **Reports are unchanged**: they still count by the date money moved (the
  user's choice), so they match the cash in the bank.

Stored as the month's first day in `expenses.for_month` and
`financial_transactions.for_month` (tenant migration `2026_10_30_100000`);
the API shows `YYYY-MM`. Model trait `App\Models\Concerns\HasForMonth`.

## Currency

Expense and Record Income forms have a Currency (Dollar / Khmer Riel). It
starts on the school's default currency (Settings). Lists and the expense
detail show each amount in its own currency.

- `expenses.currency` is new; older expenses have none and show the
  school's default.
- Paying an expense posts the ledger entry in the expense's currency;
  manual income posts in the chosen one. Reports already convert mixed
  currencies with Settings > Currency rates.

## Searchable boxes

The account boxes on the expense form, the Record Income form and the Pay
expense form, and the expense payment method, are type-to-search
(`SearchableSelect`).

Tests: `tests/Feature/Accounting/ForMonthAndCurrencyTest.php`.
