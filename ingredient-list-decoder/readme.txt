=== Ingredient List Decoder ===
Contributors: kdna
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reads a skincare ingredient list as a whole and explains how the formula is built. A tool for Apotheca.

== Description ==

The Ingredient List Decoder lets someone paste in the ingredient list from any skincare product and get back a plain reading of how that formula is built. It is not a glossary: it reads the list as a whole, the way a formulator does.

This readme tracks what each build stage adds, so the plugin can be tested one stage at a time.

= Stage 1: foundation, data layer and settings framework =

Everything later stages build on:

* The `ild_ingredient` custom post type. It has a full admin interface but is not publicly queryable, because an ingredient is a database record, not a page.
* Two taxonomies, seeded on activation with the terms from the brief:
  * **Ingredient Family** (`ild_family`) — applied to ingredients only.
  * **Skin Topic** (`ild_topic`) — shared between ingredients and standard posts, so one tag can link an ingredient to the articles worth reading next.
* The controlled **role vocabulary**, defined once in `ILD_Roles` so it can never drift, and exposed on the ingredient screen as a multi-select.
* The ingredient **meta fields**: also known as, role, typical use range (low and high), the below-one-per-cent marker, description, evidence note and founder take.
* A single **Settings page** with a section-registration API. Every later stage adds its own section to this one page rather than creating a page of its own. The General section is registered now with the email sender name, the email sender address, the cookie duration, and the opt-in "delete data on uninstall" control.
* Translation-ready throughout, everything prefixed `ild_`.

**A note on the "status" field.** The brief lists a `status` field with three values — Draft, needs review, published. This is implemented as a real WordPress **post status** (a custom "Needs Review" status sits between the built-in Draft and Published) rather than as a separate meta field. That keeps a single source of truth for an entry's state and makes the status column, the status filters and the "nothing unreviewed reaches the front end" rule all work the native WordPress way in later stages.

= Stage 2: CSV importer and exporter =

An **Import / Export** screen under the Ingredient Decoder menu.

* **Import** a UTF-8 CSV whose header row uses the field names above. Nothing is written on upload: a **column mapping screen** is shown first, with each column auto-matched to a field by name and editable before you commit. On import, a new INCI name creates a new ingredient and an existing one is updated in place — never a duplicate (matching is case-insensitive on the INCI name). Everything imports as **needs review**, never published. The result is a summary of created / updated / skipped counts, with the row number and reason for every skipped row.
* **Export** the whole library to a CSV using the same column names, so a file can be exported, edited and imported straight back — a lossless round trip.
* Roles accept either the label ("pH adjuster") or the slug ("ph-adjuster"). Families and topics may hold several values separated by a pipe. The below-1% marker accepts yes / y / 1 / true.
* Guards throughout: a manage_options capability check, a nonce on every step, every field sanitised, and a 2 MB file-size cap. The upload is held in a transient between the mapping and import steps, so no CSV is left on the server.

**A note on the imported "status" column.** The importer accepts a status column so the header matches the field list and a round trip works, but it never uses it to publish: per the brief, every imported and updated row is set to "needs review" regardless of what the file says.

= Stage 3: the ingredient library admin screens =

Everything that makes a growing library workable for whoever is curating it.

* A tuned **list screen**. Columns for INCI name, Family, Role, Status and Last modified, each one sortable. (Sorting by role orders on the stored role value; sorting by family orders on the first family name.)
* **Filters** above the list, for Status, Family, Role and the below-1% marker. They combine, so you can narrow to, say, every humectant still needing review.
* **Search** that looks in both the INCI name and the "also known as" aliases, so a common name or a misspelling still finds the right entry.
* **Bulk actions**: set the status of the selection (Published, Needs Review or Draft), add a family or a topic to the selection (you pick the term on a short confirmation screen), and export just the selected rows to CSV using the same columns as the full export.
* **Duplicate blocking.** Saving an entry whose INCI name already belongs to another entry keeps the new one as a draft and shows a clear message naming — and linking to — the entry that already holds the name. Two entries can never share an INCI name.
* **Revisions** are enabled on the ingredient post type, so a bad edit to an entry can be rolled back from the editor.
* A **Review Queue** screen under the Ingredient Decoder menu: a single list of everything still in draft or needing review, ordered alphabetically. (Once ingredients can be requested from the front end, in a later stage, the most-requested entries will rise to the top — the ordering is left open for that via a filter.)

== How to test Stage 1 ==

1. Zip the `ingredient-list-decoder` folder and upload it under Plugins → Add New → Upload Plugin, then activate it. Confirm no error appears.
2. A new **Ingredient Decoder** menu appears in the admin sidebar.
3. Open **Ingredient Families** and **Skin Topics** under it and confirm the seeded terms from the brief are present.
4. Add a new ingredient. Confirm the **Ingredient details** box shows: Also known as, Role (a set of checkboxes), the two use-range fields, the below-1% checkbox, and the description, evidence note and founder take fields.
5. In the Publish box, confirm the status dropdown offers **Needs Review** alongside Draft and Published. Save as Needs Review and confirm the label sticks in the list.
6. Open **Ingredient Decoder → Settings**. Confirm the General section shows sender name, sender address, cookie duration and the delete-on-uninstall checkbox. Change a value, save, and confirm it persists.
7. Deactivate the plugin: confirm your ingredients, terms and settings are all still there when you reactivate.
8. (Optional, destructive) Tick "Delete all plugin data when the plugin is deleted", save, then delete the plugin and confirm the data is removed. Leave it unticked to keep everything.

== How to test Stage 2 ==

1. Export first: open **Ingredient Decoder → Import / Export** and click **Export all ingredients (CSV)**. With an empty library you get a header-only file; use it as a template.
2. Fill in two or three rows in that file (at least an inci_name; try roles as labels like "Humectant | Emollient", a family and a topic, and a use range). Save as CSV.
3. Import it: choose the file, click **Upload and continue**, confirm the mapping screen has auto-matched every column, then **Import ingredients**.
4. Confirm the summary shows the right created / updated / skipped counts, and that every ingredient appears in the list as **Needs Review**.
5. Import the same file again and confirm the entries are **updated**, not duplicated.
6. Add a row with a blank inci_name and a duplicate INCI name; confirm both are reported as skipped with a reason and a row number.
7. Export again and confirm your imported data round-trips back out with the same column names.

== How to test Stage 3 ==

1. Add several ingredients (or import them from Stage 2), giving them different families, roles and statuses, and set the below-1% marker on a couple.
2. Open **Ingredient Decoder → All Ingredients**. Confirm the columns show INCI name, Family, Role, Status and Last modified, and that clicking each column header sorts by it.
3. Use the **Status**, **Family**, **Role** and **below-1%** dropdowns and click **Filter**. Confirm the list narrows, and that combining two filters narrows it further.
4. In the search box, type part of an alias you saved under "Also known as" (not the INCI name). Confirm the entry is found.
5. Tick a few entries and use **Bulk actions → Set status: Published**, then **Apply**. Confirm the count notice appears and the statuses change.
6. Tick a few entries and use **Bulk actions → Add to family…**. On the confirmation screen, choose a family and apply. Confirm the family is added (existing families are kept, not replaced).
7. Tick a few entries and use **Bulk actions → Export selected to CSV**, then click **Download CSV** in the notice. Confirm only the selected rows are in the file.
8. Add a new ingredient whose INCI name exactly matches an existing one and try to publish it. Confirm it is held as a draft and a message names the existing entry with an edit link.
9. Open **Ingredient Decoder → Review Queue**. Confirm every draft and needs-review entry is listed alphabetically, and that published entries are not.
10. Edit an entry twice, then open the editor's Revisions to confirm the earlier version can be restored.

== Changelog ==

= 0.3.0 =
* Stage 3: the ingredient library admin screens — sortable columns, status/family/role/below-1% filters, alias-aware search, bulk status and term actions, export-selected, duplicate-INCI blocking, and a review queue.

= 0.2.0 =
* Stage 2: CSV importer with a column-mapping step and update-not-duplicate behaviour, plus a matching whole-library CSV export.

= 0.1.0 =
* Stage 1: foundation, data layer and settings framework.
