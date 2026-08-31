=== Ingredient List Decoder ===
Contributors: kdna
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
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

== How to test Stage 1 ==

1. Zip the `ingredient-list-decoder` folder and upload it under Plugins → Add New → Upload Plugin, then activate it. Confirm no error appears.
2. A new **Ingredient Decoder** menu appears in the admin sidebar.
3. Open **Ingredient Families** and **Skin Topics** under it and confirm the seeded terms from the brief are present.
4. Add a new ingredient. Confirm the **Ingredient details** box shows: Also known as, Role (a set of checkboxes), the two use-range fields, the below-1% checkbox, and the description, evidence note and founder take fields.
5. In the Publish box, confirm the status dropdown offers **Needs Review** alongside Draft and Published. Save as Needs Review and confirm the label sticks in the list.
6. Open **Ingredient Decoder → Settings**. Confirm the General section shows sender name, sender address, cookie duration and the delete-on-uninstall checkbox. Change a value, save, and confirm it persists.
7. Deactivate the plugin: confirm your ingredients, terms and settings are all still there when you reactivate.
8. (Optional, destructive) Tick "Delete all plugin data when the plugin is deleted", save, then delete the plugin and confirm the data is removed. Leave it unticked to keep everything.

== Changelog ==

= 0.1.0 =
* Stage 1: foundation, data layer and settings framework.
