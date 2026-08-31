=== Ingredient List Decoder ===
Contributors: kdna
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.8.0
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

= Stage 4: the parser and matcher =

The engine that turns a pasted ingredient list into a structured, ordered read of the library. No front end yet — it is exposed as plain PHP and as a diagnostic screen.

* **Parsing.** `ild_parse_ingredient_list( $raw )` cleans raw pasted text: it splits on commas, semicolons and line breaks; strips bracketed translations, asterisks and trailing full stops; drops any "may contain" / pigment tail; turns the supplier "(and)" blend separator into a real split; collapses whitespace and normalises case. Order is preserved from start to finish, because an ingredient list is ranked by amount and that order is the whole basis of the analysis.
* **Matching.** `ild_match_ingredient_list( $raw )` looks each cleaned token up against the INCI name first, then against the "also known as" aliases. Anything still unmatched gets a fuzzy match, which returns a single best suggestion only when it is close enough to be believable (an edit-distance threshold that scales with the token's length, and never fires on very short tokens); otherwise the token is returned as unmatched.
* **Output.** A structured array whose ordered `items` list is the spine of the later analysis — each item carries its position, the original token, and whether it matched (with the post ID), was a suggestion (with the original token and the suggested entry), or was unmatched. The same outcomes are also grouped into `matched`, `suggestions` and `unmatched` for convenience, with counts.
* **Safety.** The input length is capped; anything implausibly long (a whole page pasted by mistake) is rejected rather than processed.
* **Test screen.** *Ingredient Decoder → Test Parser* — paste a list and see, in original order, how every token was split, cleaned and matched. Nothing is saved.

= Stage 5: the analysis engine =

The logic that reads a matched, ordered list as a formula. It is not AI: it is the section-6 rules applied to the ingredient metadata, so it is instant and returns the same answer every time. It produces **findings only** — no HTML and no wording. Every finding carries a **confidence flag** and the **underlying data**, so a later stage can phrase it as an inference rather than a fact.

* `ild_analyse_ingredients( $match_result )` takes the Stage 4 output and returns `{ findings, meta }`. `ild_analyse_ingredient_list( $raw )` runs the whole pipeline from raw text.
* **The one per cent line.** Finds the first ingredient flagged as a sub-one marker and confirms it with a second marker further down; everything from the line onward is treated as likely below one per cent. With a second marker the confidence is high; with only one it is low; **with no marker at all the line is returned as undetermined, never guessed.**
* **The actives.** Each active's position is recorded, along with which side of the line it sits on (above, below, or undetermined when there is no line).
* **The base.** Roles are counted across the top third of the list to say what the product is built on before anything else is added.
* **The shape.** Zero or more observations: an unusually short or long list, fragrance sitting in the top half, or a heavily loaded top third (several actives crowded near the top).
* Confidence reflects the evidence: list length is factual (high), while inferences about the line, the base and the actives are tempered by how much of the list could actually be matched. The thresholds are filterable via `ild_analysis_config`.
* The engine returns findings and stops. It does not decide what to say about them.

The *Test Parser* screen now also shows the raw findings array beneath the match table, as a developer diagnostic (no phrasing applied).

= Stage 6: the front-end tool (shortcode) =

The public tool, as a shortcode — `[ingredient_decoder]` — with all the wording in one file and all the markup in templates, ready for Stage 7 to style. It is deliberately unstyled.

* **The form.** A textarea to paste or type a list, an optional product-name field (captured for later stages but never shown in the reading — no product mentions), a submit button, and a hidden honeypot field against bots.
* **AJAX, no reload.** Submitting posts to admin-ajax with a nonce; the engine runs and a rendered HTML fragment is dropped straight into the page. Loading, empty and error states are real templates, not afterthoughts.
* **The result, in three parts.** A short descriptive summary of what the formula is built on; an ordered list of every ingredient showing its role and family, with the full description expanding on tap; and an empty region reserved for the read-next block (Stage 9).
* **The voice (section 7).** Every inferred finding is hedged — "appears to sit below the one per cent line", never a stated percentage — and the standing caveat notes that order below one per cent is unregulated and *some brands* list it alphabetically. No brand names, no verdicts, no product mentions anywhere in the output.
* **Three unmatched states, handled distinctly.** A did-you-mean suggestion; a plausible ingredient we don't have in the library yet; and a token that couldn't be read at all.
* **Safety.** Every field is sanitised, all output is escaped, the request is nonced, and the honeypot is checked.
* **Where the wording lives.** All copy is in `ILD_Phrases` (one file), turned into a view model by `ILD_Presenter`, and rendered by the templates in `/templates`. Editing the voice never means touching logic or markup.

= Stage 7: the Elementor widget =

A native Elementor widget that wraps the very same tool the shortcode renders, so the wording and markup never drift. The **shortcode keeps working** as a fallback — the widget wraps the rendering, it does not replace it. It sits in a **KDNA Tools** category, loads its assets only on pages where it is placed, and is built for Elementor's optimized markup (a single wrapper div, no reliance on Elementor's container divs).

* **The full control set from section 11.** Groups A, D, E, F, G, H and K, with responsive values on every typography and spacing control. Because the controls emit CSS scoped to the widget, and the AJAX result lands inside that widget, a designer's styling reaches the summary, the ingredient rows and every state as well as the form.
  * **A — Input & form:** input-container background, border, radius, shadow and padding; label typography; textarea typography, colour, placeholder colour, background, border, radius, minimum height and focus state; helper and character-count typography; the product-name field shown or hidden with its own styling.
  * **D — Buttons:** primary, secondary and text-link, each with typography, padding, radius, border, icon size and spacing, transition, a per-breakpoint full-width toggle, and normal / hover / focus / active / disabled states.
  * **E — Summary:** block background, border, radius, padding and shadow; heading and body typography; accent colour; an optional icon.
  * **F — Ingredient rows:** row and alternating-row background, padding, divider colour and width; position-number and INCI-name typography; role and family badges (typography, background, radius, padding) with a badge gap; expand-icon colour, size and open rotation; expanded-panel background, padding and border; separate typography for description, evidence note and founder take, with an accent border on the founder take.
  * **G — Findings & unmatched:** typography, colour and an optional icon per confidence level (high, medium, low) plus a findings block background and border; suggestion typography and link colour; a not-in-library notice with background, border, typography and icon.
  * **H — States:** loading indicator colour and size with a spinner-or-skeleton choice; empty-state typography and icon; error-state background, border and typography; a rate-limit message style (ready for Stage 14).
  * **K — Global:** maximum width, section spacing, a reduce-motion toggle, and a print-stylesheet toggle.
* **A preview-state control**, editor-only: pick Form, Loading, Result, Empty or Error and the widget renders that state in the editor so it can be styled without triggering it. It has no effect on the live page.
* **Accessibility.** Every field is labelled, the whole tool is keyboard-operable (the expandable rows are native `<details>`), focus is always visible, the tool marks itself busy while a request is in flight, and focus moves to the result when it loads so screen readers announce it.
* **Optional icons.** Where an element is rendered by the widget itself (the buttons), a full Elementor icon picker is used. Where an element is delivered by the AJAX result (summary, states, confidence, not-in-library), the icon is an optional slot a designer switches on by giving it a size and a colour or background image — honest, and fully in their hands.
* **Where the styling lives.** A small base stylesheet (`assets/css/frontend.css`) gives the plain shortcode a usable, accessible baseline — layout, the expandable rows, the spinner and skeleton, reduced-motion and print handling, visible focus. The widget's controls layer the brand look on top and always win.

= Stage 8: photo upload and the verification step =

A second way to give the tool a list: a photo of the back of the pack. It is part of the same widget, beside the textarea, and the shortcode carries it too.

* **Upload or camera.** A dropzone that accepts a single JPEG, PNG or HEIC photo, with a "take a photo" control that opens the camera on a phone. iPhone HEIC photos are handled explicitly.
* **Prepared in the browser.** The photo is converted and shrunk on the device before anything is uploaded, so a large phone photo becomes a small JPEG. HEIC is decoded natively where the browser can (Safari, i.e. most iPhones) and converted with a small library elsewhere, loaded only when a HEIC actually needs it.
* **Transcription only.** The image is sent to the Anthropic API with a transcription-only instruction — read the printed ingredient list, add nothing, translate nothing, interpret nothing. The returned text is written into a **verification area, not into the analysis.**
* **She checks it first.** The transcription appears in an editable field with the photo thumbnail beside it (stacking on mobile) so she can compare the two, correct anything misread, and only then confirm. Nothing is analysed until she does; a "use a different photo" button starts over.
* **The photo is deleted immediately.** The uploaded image is read once, transcribed, and deleted from the server the instant the text comes back — on every path, including errors. No copy is ever stored, and nothing about the image beyond its printed text is used.
* **Settings.** A new Photo transcription section holds the Anthropic API key, the model (a fast vision model by default) and the maximum photo size. With no key set, the photo control simply doesn't appear and the tool works by typing as before.
* **The full control set from section 11 groups B and C.** Group B (upload): dropzone background, border, radius and padding with separate normal, hover, drag-over, uploading and error states; icon colour and size; prompt and hint typography; progress-bar colour, height and radius; thumbnail size and radius. Group C (verification): container background, border, radius and padding; heading typography; notice typography and colour; transcription-field typography, background and border; and independently styled confirm and retake buttons. Every dimensional control is responsive, and the editor's preview-state control gains a Photo verification option so the whole step can be styled without uploading anything.

**A note on the transcription library.** HEIC conversion for non-Safari browsers uses `heic2any`, loaded on demand from a CDN. The URL is filterable (`ild_heic_converter_url`) so it can be pointed at a bundled copy on sites that must avoid third-party requests; Safari and iPhone photos never need it.

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

== How to test Stage 4 ==

1. Add or import a handful of ingredients (for example Aqua, Glycerin, Phenoxyethanol, Sodium Hyaluronate), and give Aqua an alias of "Water" in its "Also known as" field.
2. Open **Ingredient Decoder → Test Parser**.
3. Paste a realistic list, e.g. `Aqua (Water), Glycerin, Cetearyl Alcohol (and) Ceteareth-20, Sodium Hyaluronate*, Phenoxyethanol. May contain: CI 77891 (Titanium Dioxide).`
4. Confirm the result table, in the original order: "Aqua (Water)" reads as **Aqua**, "Water" would match Aqua by alias, the "(and)" blend has been split into two separate tokens, the asterisk and the bracketed translations are gone, and everything after "May contain" has been dropped.
5. Add a deliberate typo, e.g. `Glcerin`, and confirm it comes back as a **suggestion** pointing at Glycerin.
6. Add a nonsense token, e.g. `Zzzzqqx`, and confirm it comes back as **unmatched**.
7. Paste something enormous (tens of thousands of characters) and confirm it is rejected with a clear message rather than processed.

== How to test Stage 5 ==

1. Make sure a few ingredients carry the right metadata: mark a couple as sub-one (for example Phenoxyethanol, Sodium Hyaluronate), give one or two the "active" role, and give a fragrance entry the "fragrance" role.
2. Open **Ingredient Decoder → Test Parser** and paste a realistic list.
3. Below the match table, read the **Analysis findings** dump. Confirm:
   * the `one_percent_line` finding locates the line at the first sub-one marker, and is only `confirmed` (high confidence) when a second marker appears further down;
   * each active is listed with a `side` of above or below that line;
   * the `base` finding counts roles across the top third;
   * shape findings appear where relevant (short or long list, fragrance in the top half, a loaded top third).
4. Paste a list with no sub-one ingredients at all and confirm the line comes back as `undetermined`, not guessed.
5. Confirm every finding carries a `confidence` flag and the numbers behind it, and that the order of the list is preserved throughout.

== How to test Stage 6 ==

1. Create a page and add the shortcode `[ingredient_decoder]`, then view it while logged out (an incognito window is easiest).
2. Confirm the form shows a textarea, an optional product-name field, and a submit button, with no styling.
3. Paste a real ingredient list and submit. Confirm the page does not reload, a brief loading message appears, and then a result appears in three parts: a summary of what the formula is built on, an ordered list of every ingredient with its role and family, and an empty read-next region.
4. Tap an ingredient's **Detail** toggle and confirm its full description expands and collapses.
5. Confirm the summary hedges — "appears to sit below the one per cent line" — and never states a percentage, and that no brand or product name appears anywhere.
6. In the list, confirm the three unmatched states read differently: a typo shows a "did you mean…" line, a real-but-unknown ingredient shows "we don't have this one yet", and gibberish shows "we couldn't read this one".
7. Submit an empty box (or nonsense) and confirm the empty state appears. Paste something enormous and confirm the error state appears. Both should look like proper messages, not raw errors.
8. Enter the product name and confirm it never shows up in the reading.

== How to test Stage 7 ==

1. With Elementor active, edit a page and open the widget panel. Confirm a **KDNA Tools** category holds an **Ingredient List Decoder** widget. Drag it in.
2. Confirm the tool appears and works exactly as the shortcode does: paste a list, submit, and see the three-part result without a page reload. (Confirm the `[ingredient_decoder]` shortcode still works too, on a separate page.)
3. In the widget's **Content** tab, change **Preview state** to Result, then Empty, then Error, then Loading. Confirm each state renders in the editor so you can style it. Confirm the live page is unaffected by this control.
4. Work through the **Style** tab groups (A input & form, D buttons, E summary, F rows, G findings & unmatched, H states, K global). Change a colour, a typography and a spacing control in each and confirm the tool updates — including the parts that only appear in a result (summary, rows, badges, expanded panel).
5. Confirm every spacing and typography control offers the responsive (desktop / tablet / mobile) device switcher.
6. In group D, set a primary-button icon (Content tab) and confirm it appears in the button; toggle a button to full width on mobile only and confirm it is full width on mobile and auto on desktop.
7. In group K, turn **Reduce motion** on and confirm the spinner and expand animation stop. Turn **Print styles** on and use the browser's print preview to confirm the form is hidden and every ingredient's detail is expanded.
8. In group H, switch the loading indicator from Spinner to Skeleton and submit a list; confirm the skeleton bars show instead of the spinner.
9. Keyboard only: tab to the textarea, type, tab to submit and press Enter; when the result loads, confirm focus lands on it and a screen reader announces it. Tab to an ingredient's detail toggle and press Enter/Space to expand and collapse it.
10. Confirm the tool's assets load only on the page holding the widget or shortcode, not site-wide.

== How to test Stage 8 ==

1. Open **Ingredient Decoder → Settings** and, in the **Photo transcription** section, paste an Anthropic API key and save. (Leave the model and size at their defaults.)
2. View the tool logged out. Confirm a photo dropzone now appears beside the textarea, with "Choose a photo" and "Take a photo".
3. Choose a clear JPEG or PNG photo of an ingredient list. Confirm a brief progress indicator, then a verification area showing the photo thumbnail beside an editable transcription — and that the list has NOT been analysed yet.
4. On a phone, use "Take a photo" and confirm the camera opens; try an iPhone (HEIC) photo and confirm it is read.
5. Correct a word in the transcription, then press **Confirm and read the formula**. Confirm the engine now runs on the corrected text and the normal three-part result appears.
6. Repeat, and this time press **Use a different photo**; confirm the verification clears and the dropzone returns.
7. Try a photo larger than the size limit and a non-image file; confirm each is refused with a clear message and nothing is uploaded.
8. Confirm no product or brand name from the photo appears in the reading, and that the transcription step never states a percentage.
9. Clear the API key in Settings and save; confirm the photo control disappears and the tool still works by typing.
10. In Elementor, set the widget's **Preview state** to Photo verification and confirm the dropzone and verification area render for styling; work through the **B · Photo upload** and **C · Verification** style groups.
11. (Privacy) Confirm that after a transcription no image file is left in the uploads folder — the photo is deleted immediately after it is read.

== Changelog ==

= 0.8.0 =
* Stage 8: read the list from a photo. An upload-and-camera control beside the textarea accepts a single JPEG, PNG or HEIC, converted and resized in the browser (HEIC handled explicitly) before upload. The image is sent to the Anthropic API for transcription only, then deleted from the server immediately; the returned text goes into a verification step — thumbnail beside an editable field — that must be confirmed before the engine runs. Adds a Photo transcription settings section (API key, model, size) and the section-11 groups B and C style controls, with a Photo verification preview state.

= 0.7.0 =
* Stage 7: a native Elementor widget in a KDNA Tools category that wraps the same tool the shortcode renders (the shortcode stays as a fallback). The full section-11 control set — groups A, D, E, F, G, H and K — with responsive typography and spacing throughout, an editor-only preview-state control, spinner-or-skeleton loading, reduce-motion and print toggles, and full keyboard operation with visible focus and result announcements. Adds a base stylesheet, a live character count, evidence-note and founder-take in the expanded rows, and confidence markers on the summary points.

= 0.6.0 =
* Stage 6: the front-end tool as the [ingredient_decoder] shortcode — AJAX with no reload, a three-part result (summary, ordered ingredient list with detail on tap, reserved read-next region), and real loading/empty/error templates. All wording in one phrases file (Apotheca voice, hedged inferences, no brand names or verdicts), all markup in templates for Stage 7 to style. Three unmatched states handled distinctly; nonce, honeypot, sanitised input and escaped output throughout.

= 0.5.0 =
* Stage 5: the analysis engine — locates the probable one per cent line from sub-one markers (undetermined when none), places each active against it, describes the base from the top third, and notes shape observations. Findings only, each with a confidence flag and its underlying data; no phrasing. Exposed as ild_analyse_ingredients() and ild_analyse_ingredient_list(), with a raw findings view on the Test Parser screen.

= 0.4.0 =
* Stage 4: the parser and matcher — cleans a pasted list into ordered tokens, matches on INCI name then aliases, offers a single fuzzy suggestion within a length-scaled threshold, caps the input length, and adds a Test Parser diagnostic screen. Exposed as ild_parse_ingredient_list() and ild_match_ingredient_list().

= 0.3.0 =
* Stage 3: the ingredient library admin screens — sortable columns, status/family/role/below-1% filters, alias-aware search, bulk status and term actions, export-selected, duplicate-INCI blocking, and a review queue.

= 0.2.0 =
* Stage 2: CSV importer with a column-mapping step and update-not-duplicate behaviour, plus a matching whole-library CSV export.

= 0.1.0 =
* Stage 1: foundation, data layer and settings framework.
