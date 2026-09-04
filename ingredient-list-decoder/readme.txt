=== Ingredient List Decoder ===
Contributors: kdna
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.6.14
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
  * **Ingredient Family** (`ild_family`) — applied to ingredients only. Includes Pigments and fillers, Silicones and Surfactants alongside the skincare families.
  * **Skin Topic** (`ild_topic`) — shared between ingredients and standard posts, so one tag can link an ingredient to the articles worth reading next.
* The controlled **role vocabulary**, defined once in `ILD_Roles` so it can never drift, and exposed on the ingredient screen as a multi-select. It covers colour cosmetics as well as skincare (36 roles: the original skincare set plus solubiliser, carrier, preservative booster, buffering, stabiliser, fragrance allergen, pigment, opacifier, absorbent, filler, slip modifier, binder, coating, dispersant, wax, silicone, exfoliant, soothing, astringent and viscosity modifier).
* The ingredient **meta fields**: also known as, role, typical use range (low and high), the below-one-per-cent marker, a **marker confidence** (strong or moderate, only used where the below-1% marker is ticked), a **category** (Skincare, Colour or Both, for filtering only — the engine never reads it), description, evidence note and founder take.
* A single **Settings page** with a section-registration API. Every later stage adds its own section to this one page rather than creating a page of its own. The General section is registered now with the email sender name, the email sender address, the cookie duration, and the opt-in "delete data on uninstall" control.
* Translation-ready throughout, everything prefixed `ild_`.

**A note on the "status" field.** The brief lists a `status` field with three values — Draft, needs review, published. This is implemented as a real WordPress **post status** (a custom "Needs Review" status sits between the built-in Draft and Published) rather than as a separate meta field. That keeps a single source of truth for an entry's state and makes the status column, the status filters and the "nothing unreviewed reaches the front end" rule all work the native WordPress way in later stages.

= Stage 2: CSV importer and exporter =

An **Import / Export** screen under the Ingredient Decoder menu.

* **Import** a UTF-8 CSV whose header row uses the field names above. Nothing is written on upload: a **column mapping screen** is shown first, with each column auto-matched to a field by name and editable before you commit. On import, create versus update is decided on the **normalised INCI key** from the duplicate-prevention work, so case, spacing, edge punctuation and dash style all update the right entry rather than creating a second one. Where one file holds the same key more than once, the **last occurrence wins** and each earlier one is skipped, named with the row number that superseded it. Everything imports as **needs review**, never published. The result is a summary of created / updated / skipped counts, with the row number and reason for every skipped row.
* **Export** the whole library to a CSV using the same column names, so a file can be exported, edited and imported straight back — a lossless round trip.
* The columns include **marker_confidence** (strong / moderate) and **category** (Skincare / Colour / Both) alongside the rest. Roles accept either the label ("pH adjuster") or the slug ("ph-adjuster"), and so do marker_confidence and category. Families and topics may hold several values separated by a pipe. The below-1% marker accepts yes / y / 1 / true. Marker confidence is only kept where the below-1% marker is set, matching the edit screen.
* Guards throughout: a manage_options capability check, a nonce on every step, every field sanitised, and a 2 MB file-size cap. The upload is held in a transient between the mapping and import steps, so no CSV is left on the server.

**A note on the imported "status" column.** The importer accepts a status column so the header matches the field list and a round trip works, but it never uses it to publish: per the brief, every imported and updated row is set to "needs review" regardless of what the file says.

= Stage 3: the ingredient library admin screens =

Everything that makes a growing library workable for whoever is curating it.

* A tuned **list screen**. Columns for INCI name, Family, Role, Category, 1% marker, Status, Topic and Last modified. INCI name, Family, Role, Status and Last modified are sortable. (Sorting by role orders on the stored role value; sorting by family orders on the first family name.)
* **Filters** above the list, for Status, Family, Role, the below-1% marker, marker confidence and category. They combine, so you can narrow to, say, every strong-confidence 1% marker in the Colour category still needing review.
* **Search** that looks in both the INCI name and the "also known as" aliases, so a common name or a misspelling still finds the right entry.
* **Bulk actions**: set the status of the selection (Published, Needs Review or Draft), add a family or a topic to the selection (you pick the term on a short confirmation screen), and export just the selected rows to CSV using the same columns as the full export.
* **Duplicate prevention, on every route.** Every ingredient carries a normalised key derived from its INCI name — lower-cased, whitespace collapsed, leading and trailing punctuation stripped, and every hyphen or en-dash variant folded to a plain hyphen — and all duplicate checks compare that key, never the raw string. The keys live in their own table with a UNIQUE index, so the database itself refuses a second entry for the same key even if two saves arrive at once; a PHP check alone could not. The guard covers creation and renaming alike, and every path that reaches them: the editor, Quick Edit, the CSV importer and the AI drafter. A blocked save is kept as a draft with a clear message naming and linking to the entry that already holds the key. On the Add New / edit screen the check also runs **as you type**: an exact key match blocks the save and names the existing entry; a match against another entry's **also known as** alias is a warning (not a block) that names the holder; and a close **near-match** (via the same fuzzy matcher the reader uses) is a warning that always allows the save, because Ceramide NP and Ceramide AP are different ingredients.
* **Revisions** are enabled on the ingredient post type, so a bad edit to an entry can be rolled back from the editor.
* A **Review Queue** screen under the Ingredient Decoder menu: a single list of everything still in draft or needing review, ordered alphabetically. (Once ingredients can be requested from the front end, in a later stage, the most-requested entries will rise to the top — the ordering is left open for that via a filter.)

= Stage 4: the parser and matcher =

The engine that turns a pasted ingredient list into a structured, ordered read of the library. No front end yet — it is exposed as plain PHP and as a diagnostic screen.

* **Parsing.** `ild_parse_ingredient_list( $raw )` cleans raw pasted text: it splits on commas, semicolons and line breaks; removes asterisks and trailing full stops; turns the supplier "(and)" blend separator into a real split; collapses whitespace and normalises case. **Bracketed content is kept**, because the brackets usually carry the common name (Aqua (Water), Butyrospermum Parkii (Shea) Butter) and keeping them helps a token line up with the stored INCI name. Order is preserved from start to finish, because an ingredient list is ranked by amount and that order is the whole basis of the analysis.
* **The shade declaration.** Everything after a "may contain", "+/-" or "±" marker is a shade range, not a concentration-ordered list, so it is **held apart and never ranked**. Inside it, the individual CI colourants are collapsed to a single flag — the reading says the product contains colourants without naming them. Titanium Dioxide and Zinc Oxide are the exceptions (matched by name or by their CI numbers 77891 / 77947): they double as UV filters and opacifiers, so they keep their full entries wherever they appear. The shade block comes back as a separate `shade` element of the output.
* **Matching.** `ild_match_ingredient_list( $raw )` looks each cleaned token up against the INCI name first, then against the "also known as" aliases. A token whose brackets carry only a common name (Aqua (Water)) is retried with the parenthetical removed, so it still finds its entry, while a name whose stored INCI itself contains a parenthetical matches directly. Anything still unmatched gets a fuzzy match, which returns a single best suggestion only when it is close enough to be believable (an edit-distance threshold that scales with the token's length, and never fires on very short tokens); otherwise the token is returned as unmatched.
* **Output.** A structured array whose ordered `items` list is the spine of the later analysis — each item carries its position, the original token, and whether it matched (with the post ID), was a suggestion, or was unmatched — plus a separate `shade` element ({ present, colourants, items }) for the shade range. The same item outcomes are also grouped into `matched`, `suggestions` and `unmatched` for convenience, with counts.
* **Safety.** The input length is capped; anything implausibly long (a whole page pasted by mistake) is rejected rather than processed.
* **Test screen.** *Ingredient Decoder → Test Parser* — paste a list and see, in original order, how every token was split, cleaned and matched. Nothing is saved.

= Stage 5: the analysis engine =

The logic that reads a matched, ordered list as a formula. It is not AI: it is the section-6 rules applied to the ingredient metadata, so it is instant and returns the same answer every time. It produces **findings only** — no HTML and no wording. Every finding carries a **confidence flag** and the **underlying data**, so a later stage can phrase it as an inference rather than a fact.

* `ild_analyse_ingredients( $match_result )` takes the Stage 4 output and returns `{ findings, meta }`. `ild_analyse_ingredient_list( $raw )` runs the whole pipeline from raw text.
* **The one per cent line.** Placement turns on the markers' confidence. A single sub-one marker of **strong** confidence is enough to place the line on its own. A **moderate** marker (an unrated marker counts as moderate) places the line only when a **second marker of either level appears further down** to corroborate it. A lone moderate marker with nothing to corroborate it — or no marker at all — leaves the line **undetermined, never guessed**. When placed, the line sits at the first marker's position and everything from there on is treated as likely below one per cent. The shade block from the parser is held apart and never contributes a marker to this logic.
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
* **Read free, in the browser, by default.** The prepared photo is read on the device with a free, in-browser reader (Tesseract.js, loaded on demand). No API key is needed, nothing costs anything, and the photo never leaves the visitor's device on this path. The recognised text is written into a **verification area, not into the analysis.**
* **An optional, more accurate AI reading.** If an Anthropic API key is set, the verification step also offers a **"Read it more accurately"** button that re-reads the same photo through the Anthropic API (transcription only — read the printed list, add nothing, translate nothing). If the free reading finds nothing at all, and a key is set, it falls back to the AI reading automatically. With no key, the tool simply stays on the free reading.
* **She checks it first.** The transcription appears in an editable field with the photo thumbnail beside it (stacking on mobile) so she can compare the two, correct anything misread, and only then confirm. Nothing is analysed until she does; a "use a different photo" button starts over.
* **The photo is deleted immediately (AI path).** When the AI reading is used, the uploaded image is read once, transcribed, and deleted from the server the instant the text comes back — on every path, including errors. No copy is ever stored, and nothing about the image beyond its printed text is used. The free browser reading uploads nothing in the first place.
* **Settings.** The Photo transcription section holds a "Read the list from a photo" switch (on by default, free), and — optionally — the Anthropic API key, the model (a fast vision model by default) and the maximum photo size. With the switch on and no key, the photo control still appears and reads for free; with the switch off, it doesn't appear and the tool works by typing as before.
* **The full control set from section 11 groups B and C.** Group B (upload): dropzone background, border, radius and padding with separate normal, hover, drag-over, uploading and error states; icon colour and size; prompt and hint typography; progress-bar colour, height and radius; thumbnail size and radius. Group C (verification): container background, border, radius and padding; heading typography; notice typography and colour; transcription-field typography, background and border; and independently styled confirm and retake buttons. Every dimensional control is responsive, and the editor's preview-state control gains a Photo verification option so the whole step can be styled without uploading anything.

**A note on the reading libraries.** The free browser reading uses `Tesseract.js`, and HEIC conversion for non-Safari browsers uses `heic2any`; both are loaded on demand from a CDN, only when a photo is actually read. Both URLs are filterable (`ild_ocr_engine_url` and `ild_heic_converter_url`) so they can be pointed at bundled copies on sites that must avoid third-party requests, and the reading language is filterable too (`ild_ocr_language`, `eng` by default). Safari and iPhone photos never need the HEIC library.

= Stage 9: the read-next block =

The reserved region beneath the result now fills itself with the articles worth reading after a particular formula — and only when there genuinely are some.

* **Driven by the findings.** It takes the ingredients that actually generated the Stage 5 findings — the sub-one markers, the actives, a high fragrance, and the ingredients across the top third behind the base finding — and gathers their Skin Topic and Ingredient Family terms.
* **Topic weighted above family.** Published posts that share those terms are found and ranked by how many terms they share, counting a shared topic for more than a shared family, and then by recency. At most three are shown.
* **Never a filler.** If no post shares at least one term, the block renders nothing at all. It never falls back to recent or popular posts — an irrelevant "read next" is worse than none.
* **Never itself.** The page the tool sits on is always excluded, so an article never suggests itself.
* **Cached, and kept fresh.** The ranked candidates are cached per term-set. A cache generation number, bumped whenever any post or ingredient is saved, retires every cached set at once, so a newly published or re-tagged article shows up straight away.
* **The full control set from section 11 group I.** Section-heading typography and spacing; card background, border, radius, shadow and content padding; thumbnail size, aspect ratio and radius; title, excerpt and meta typography; a hover state; and columns and gap per breakpoint. The editor's Result preview now includes sample cards so the whole block can be styled without running a real query.

= Stage 10: the reading, and an optional "email me a copy" =

The whole reading — the summary, every ingredient in order, and the read-next block — is shown on screen straight away. Beneath it, a short email form offers to send the reading to an inbox so the visitor can keep it. It is not a gate: nothing is hidden, and no email is needed to read the formula. (Earlier builds held the breakdown behind this form; that was reversed — the email is only for keeping a copy.)

* **The reading, in full, on screen.** On submit, the summary and the full breakdown appear immediately for everyone.
* **Optional email, one consent.** Beneath the reading, an email form offers to send a copy. Its single checkbox, unticked by default, covers emailing the reading and marketing from Apotheca®; the submit button stays disabled until it is ticked, with the reason shown rather than failing silently. Completing it emails the reading and shows a short confirmation in place of the form.
* **The terms, up front.** The same exchange wording is shown near the input, before anything is pasted, as well as at the email form.
* **Shown until subscribed.** On submission a first-party cookie is set, its duration taken from the plugin's "cookie duration" setting (twelve months by default), so the email offer is not shown again on that device.
* **What's stored.** Each capture records the address, the timestamp, the consent state, the exact consent wording shown at that moment, and the source page — kept as a private lead entry.
* **Guarded.** A honeypot field and a nonce on the form, a valid-email check, and a required consent box, all validated again on the server.
* **The full control set from section 11 group J.** Container background, border, radius, padding and shadow; heading and body typography; the email field inherited or overridden; the consent checkbox size, colour, checked colour and radius; consent-text typography and colour; the privacy-link colour; and the submit button inherited or overridden, including its disabled state. The exchange and consent wording are both editable Elementor controls (defaulting to the brief's suggested wording); the privacy-policy link and the unsubscribe line are deliberately not editable. The editor keeps a preview state for the email form so the whole thing can be styled.

= Stage 11: the branded result email =

When someone completes the gate, their reading is emailed to them — a branded HTML email built from the same result.

* **Built for email clients.** A table-based layout, with the CSS inlined onto each element by a small built-in inliner (media queries are left in the head for clients that honour them). A plain-text alternative is generated alongside from the same data, so every send is a proper multipart email.
* **The reading, flattened.** Logo, an editable intro line, the summary, and the full ingredient breakdown — shown in full, since email cannot expand and collapse — then the read-next articles with thumbnails, and a footer with the privacy link and a **working unsubscribe link on every send**. Unsubscribing (a signed, first-party link) marks the lead unsubscribed straight away.
* **Fully themeable from Settings.** A Result email section with the logo, widths, corner radius, header/page/container backgrounds, heading and body colour, size and line height, link colour, divider colour, the button (background, text, radius, padding), the font stack, and the editable intro, sign-off and footer text.
* **Web-safe by design.** The default font stack needs no web fonts, and the template's sizing and spacing are set for it — the fallback chain is what actually renders, not an afterthought.
* **A live preview and a test send.** The settings page shows a live preview that reflects unsaved changes, and sends a test to any address.
* **Through the site's mail transport.** Mail goes out via wp_mail(), so it uses whatever transactional service the site has configured (SMTP, a sending API, and so on) rather than PHP mail() directly.

= Stage 12: the leads admin screen =

A dedicated **Leads** screen under the Ingredient Decoder menu — a custom table of every captured address.

* **The columns.** Address, date, consent state, the exact consent wording shown at the time, the source page, that address's submission history, and its connector sync status.
* **Submission history.** Each lead shows the ingredient lists that address has decoded, with the date and product name where one was given, read from the submissions store by lead ID.
* **Find them.** Filter by date range and by sync status, and search by address. The filtered set exports to CSV, and individual records can be deleted.
* **The failed-sync view.** A status view lists anything a connector rejected, each row offering a **Retry** — because a connector outage is otherwise silent. (The connectors that set these statuses arrive in later stages; retry queues the address to be sent again.)
* **Delete is clean.** Deleting a lead deletes that lead's submissions in the same operation, so no orphan rows are left behind.
* **Privacy built in.** The plugin registers with WordPress's personal-data exporter and eraser, so a privacy request under Tools → Export/Erase Personal Data covers a person's lead record *and* their submission history automatically.

= Stage 13: submission storage and the unknown-ingredient queue =

Two custom tables and an admin screen for growing the library from real demand.

* **The submissions table.** Every decoded list is stored in its own table (not a post type) with the normalised ingredient list, a findings summary, the time, the optional product name, and the ID of the lead who submitted it. It is indexed on lead ID and on date. A list decoded before an address is given is stored with a null lead ID and a session token, and attached to the lead the moment the gate is completed in the same session — so nothing is orphaned. Deleting a lead deletes its submissions in the same operation.
* **The unknown-token table.** A second table records every unmatched token with a count of how often it has appeared and the date first seen. It holds no lead reference at all — it is a working queue, not a record of anyone.
* **The Unknown ingredients screen.** Tokens listed most-submitted first, with a **Dismiss** action for typos and rubbish and a **Draft entry** button. Drafting calls the Anthropic API and creates an `ild_ingredient` in **needs-review** status, populated against the section-4 field list (aliases, roles, use range, sub-one marker, description, evidence note, founder take, family and topics). **Nothing is ever published automatically** — a drafted entry always lands in needs-review for a human.
* **The API key is a wp-config constant.** Drafting reads its key from `ILD_ANTHROPIC_API_KEY` in wp-config.php, never from the options table. Without it, the draft button is simply hidden.
* **Demand orders the review queue.** The token's appearance count is carried onto the drafted entry, and the Stage 3 review queue is now ordered by that count — the most-requested drafts rise to the top, hand-added entries below.

**Defining the drafting key.** Add this to wp-config.php:

`define( 'ILD_ANTHROPIC_API_KEY', 'sk-ant-...' );`

= Stage 14: rate limiting, caching and the dashboard =

The guards that keep the tool fast, cheap and abuse-resistant, plus an at-a-glance panel.

* **Per-IP limits.** A configurable hourly limit on analyses, and a separate, tighter one on photo uploads (each of which costs money to read). Go over and you get a calm "you're going a little fast" message, not an error.
* **A hard daily cost cap.** A single, site-wide daily cap on every request that costs money — photo reads and AI drafts. When it's reached, the tool asks people to type the list instead or come back tomorrow, gracefully, rather than erroring or running up a bill.
* **Nothing that identifies a person is stored.** The per-IP counters are keyed on a salted hash of the address, never the address itself.
* **Result caching.** The complete result of decoding a list is cached, keyed on a hash of the normalised ingredient list, so a repeated list is served instantly. The whole cache is invalidated the moment any ingredient entry is saved or deleted, so an edit to the library shows up straight away. (The per-page read-next block is rebuilt each time and has its own cache.)
* **The dashboard panel.** A widget on the WordPress dashboard showing submissions this week, leads this week, the top unmatched ingredients, the library size by status, and how much of today's paid-request cap has been used — aggregate counts only.
* **Settings.** A Limits & cost section holds the two per-IP limits and the daily cap.

= Stage 15: the email-connector interface and the Campaign Monitor add-on =

The core captures every consented lead locally on its own. This stage lets those leads flow out to an email platform, without tying the core to any one provider.

* **A provider-agnostic interface.** The core defines a single PHP interface, `ILD_Email_Connector`, that any provider add-on implements — with methods to identify itself, say whether it is configured, test its connection, and push one lead. The core holds the queue, decides when a push should happen, honours consent, retries with a growing backoff, and records the outcome. A provider only has to know how to talk to its own service.
* **Leads are captured whether or not a connector is active.** With no add-on installed the leads still land in the Leads screen; there is simply nothing to push them to. Install a connector and new leads start syncing.
* **Consent is the gate.** A lead is pushed only where consent is recorded and not withdrawn. Unsubscribed and un-consented leads are never sent.
* **Failures are surfaced, never swallowed.** When a provider rejects a push, the core records it against the lead — with the provider's own message — so it appears in the **Leads → Failed sync** view with a Retry, and retries it a few times with a growing backoff first.
* **The Campaign Monitor add-on** is a separate plugin built against this interface. See its own readme. It pushes the name (where given), email, source page and consent date to a configurable list, with the capturing tool recorded as a custom field so these leads can be segmented apart from Founding Faces members. It has an API-key and list-ID setting with a test-connection button, and any rejection flows into the failed-sync view above.

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
8. Add a new ingredient whose INCI name matches an existing one — try a difference of case, spacing or a stray full stop (e.g. "glycerin " for "Glycerin"). As you type, confirm a red message names the existing entry with an "Edit it" link and the Publish/Save buttons are disabled; force a save and confirm it is held as a draft with the same message.
9. Rename an existing entry so its name collides with another entry's; confirm the same block applies to the rename, not only to new entries.
10. Type a name that you have saved as another entry's **Also known as** alias; confirm a yellow warning names that entry but the save is still allowed.
11. Type a name one or two letters away from an existing one (e.g. "Ceramide AP" when "Ceramide NP" exists); confirm a yellow near-match warning appears but the save is allowed — they are different ingredients.
12. Import a CSV containing a row that duplicates an existing INCI name by case or spacing; confirm it updates the existing entry rather than creating a second one.
13. Open **Ingredient Decoder → Review Queue**. Confirm every draft and needs-review entry is listed alphabetically, and that published entries are not.
14. Edit an entry twice, then open the editor's Revisions to confirm the earlier version can be restored.

== How to test Stage 4 ==

1. Add or import a handful of ingredients (for example Aqua, Glycerin, Phenoxyethanol, Sodium Hyaluronate), and give Aqua an alias of "Water" in its "Also known as" field.
2. Open **Ingredient Decoder → Test Parser**.
3. Paste a realistic list, e.g. `Aqua (Water), Glycerin, Cetearyl Alcohol (and) Ceteareth-20, Sodium Hyaluronate*, Phenoxyethanol. May contain: CI 77491, CI 77492, Titanium Dioxide (CI 77891), Zinc Oxide.`
4. Confirm the result table, in the original order: "Aqua (Water)" keeps its bracket and still reads as **Aqua**, the "(and)" blend has been split into two separate tokens, the asterisk and trailing full stops are gone — and the "May contain" range is **not** in the ordered list.
5. Under the table, confirm a **Shade declaration** section: it says the product contains colourants (the CI 77491 / CI 77492 numbers are not listed), and it keeps **Titanium Dioxide** and **Zinc Oxide** as named entries.
6. Add a deliberate typo, e.g. `Glcerin`, and confirm it comes back as a **suggestion** pointing at Glycerin.
7. Add a nonsense token, e.g. `Zzzzqqx`, and confirm it comes back as **unmatched**.
8. Paste something enormous (tens of thousands of characters) and confirm it is rejected with a clear message rather than processed.

== How to test Stage 5 ==

1. Make sure a few ingredients carry the right metadata: mark a couple as sub-one and set their **marker confidence** (one Strong, one Moderate — for example Phenoxyethanol Strong, Sodium Hyaluronate Moderate), give one or two the "active" role, and give a fragrance entry the "fragrance" role.
2. Open **Ingredient Decoder → Test Parser** and paste a realistic list.
3. Below the match table, read the **Analysis findings** dump. Confirm the `one_percent_line` finding:
   * a list whose first sub-one marker is **strong** places the line there (status `located`, `basis` strong), on that one marker alone;
   * a list whose only sub-one marker is **moderate** comes back `undetermined` — until a second marker (of either level) appears further down, at which point it places the line at the first marker (`basis` corroborated);
   * each active is listed with a `side` of above or below that line;
   * the `base` finding counts roles across the top third, and shape findings appear where relevant.
4. Paste a list with no sub-one ingredients at all, and separately one with a single moderate marker, and confirm both come back `undetermined`, not guessed.
5. Put a colourant shade range ("May contain: CI 77491, Titanium Dioxide") on a list whose only strong-ish marker is inside it, and confirm the line is **not** placed from the shade block — it is held apart and never ranked.
6. Confirm every finding carries a `confidence` flag and the numbers behind it, and that the order of the list is preserved throughout.

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

1. **Free reading, no key.** With no Anthropic API key set (and the **Read the list from a photo** switch on, its default), view the tool logged out. Confirm a photo dropzone appears beside the textarea, with "Choose a photo" and "Take a photo".
2. Choose a clear JPEG or PNG photo of an ingredient list. Confirm a brief "reading" indicator, then a verification area showing the photo thumbnail beside an editable transcription — read for free in the browser — and that the list has NOT been analysed yet. Confirm there is **no** "Read it more accurately" button (no key set).
3. On a phone, use "Take a photo" and confirm the camera opens; try an iPhone (HEIC) photo and confirm it is read.
4. Correct any words in the transcription, then press **Confirm and read the formula**. Confirm the engine runs on the corrected text and the normal three-part result appears.
5. Press **Use a different photo**; confirm the verification clears and the dropzone returns.
6. **The optional AI reading.** Now paste an Anthropic API key in **Settings → Photo transcription** and save. Read a photo again; in the verification step confirm a **Read it more accurately** button appears. Press it and confirm the transcription is replaced by the AI reading, with a brief status while it runs.
7. Try a photo larger than the size limit and a non-image file; confirm each is refused with a clear message and nothing is uploaded.
8. Confirm no product or brand name from the photo appears in the reading, and that the reading step never states a percentage.
9. **Switch the feature off.** Untick **Read the list from a photo** and save; confirm the photo control disappears entirely and the tool still works by typing.
10. In Elementor, set the widget's **Preview state** to Photo verification and confirm the dropzone and verification area render for styling; work through the **B · Photo upload** and **C · Verification** style groups.
11. (Privacy) Confirm the free reading uploads no file at all, and that after an AI reading no image is left in the uploads folder — it is deleted immediately after it is read.

== How to test Stage 9 ==

1. Tag a couple of published posts with a Skin Topic term, and tag an ingredient (for example an active you will paste) with that same Skin Topic.
2. Put the tool on a page and analyse a list that includes that ingredient. Confirm a "Worth reading next" block appears beneath the result, showing the matching post(s).
3. Confirm the posts are ordered by how many terms they share (a post sharing two beats one sharing one), and that a shared Skin Topic outranks a shared Ingredient Family.
4. Confirm no more than three posts appear, and that the page the tool is on never appears in the block.
5. Analyse a list whose ingredients share no term with any post. Confirm the block renders nothing at all — not recent or popular posts.
6. Publish or re-tag a post so it now shares a term, re-run the same list, and confirm the new post appears (the cache clears on save).
7. In Elementor, set the widget's Preview state to Result and work through the **I · Read-next block** style group — heading, cards, thumbnail, typography, hover, and columns/gap per breakpoint.

== How to test Stage 10 ==

1. In a fresh browser (or after clearing cookies), view the tool logged out and analyse a real list. Confirm the summary appears immediately, and beneath it an email form — not the ingredient breakdown.
2. Confirm the exchange wording is also shown near the input, before you paste anything.
3. Confirm the submit button is disabled, with a line explaining you must tick the box. Tick the consent box and confirm the button enables and the line goes away.
4. Enter an email and submit. Confirm the breakdown (the full ingredient list and, where relevant, the read-next block) appears in place of the form.
5. Reload the page and analyse another list. Confirm the gate is skipped and the breakdown shows straight away (the cookie is set).
6. In **Ingredient Decoder → Settings**, change the cookie duration; clear cookies and confirm the new duration is used.
7. As an administrator, confirm a lead was stored (they are private `ild_lead` entries; the admin screen arrives in a later stage) with the email, the consent state, the exact consent wording, and the source page.
8. Try submitting with an invalid email, or with the box unticked via dev tools — confirm the server refuses it and nothing is stored.
9. In Elementor, edit the widget: change the Exchange text and Consent checkbox text and confirm they appear on the page; confirm the privacy-policy link and unsubscribe line cannot be edited. Set the Preview state to Email gate and work through the **J · Email gate** style group, including the checkbox and the submit button's disabled state.

== How to test Stage 11 ==

1. Open **Ingredient Decoder → Settings** and find the **Result email** section. Confirm a live preview of the email appears and updates as you change colours, widths, the font stack and the intro/sign-off/footer text — before saving.
2. Upload a logo and confirm it appears in the preview header; clear it and confirm the brand name shows instead.
3. Enter your address next to "Send a test to" and click **Send test**. Confirm the email arrives, looks right, and that its plain-text version (view source / "show original") is present and readable.
4. In the received email, confirm the ingredient breakdown is shown in full (no expand/collapse), the read-next articles show with thumbnails, and the footer has a privacy link and an unsubscribe link.
5. Click the unsubscribe link and confirm it works (a confirmation page) — for a real send it marks that lead unsubscribed.
6. Complete the front-end gate with your own email and confirm the same result email is delivered to you automatically.
7. If you use an SMTP or transactional-email plugin, confirm the email goes through it (check its log) rather than PHP mail.
8. Turn off web-font loading in your mail client (or just trust the defaults) and confirm the email still reads well on the fallback fonts.

== How to test Stage 12 ==

1. Complete the gate a few times with different addresses (and some with a product name). Open **Ingredient Decoder → Leads** and confirm each address is listed with its date, consent, the consent wording, the source page, its submission history, and a sync status of "Pending".
2. Confirm the submission history shows each decoded list with its date and product name where given.
3. Use the date-range inputs and the sync-status dropdown, click Filter, and confirm the list narrows. Search an address and confirm it is found.
4. Click **Export filtered to CSV** and confirm the download contains exactly the filtered rows with all the fields.
5. Delete a lead (row action) and confirm it disappears — and that its submissions are gone too (they no longer show against any lead).
6. Open the **Failed sync** view. (It fills once a connector is configured in a later stage; a rejected address appears here with a Retry link.)
7. Go to **Tools → Export Personal Data**, request an export for one of the captured addresses, and confirm the export contains both the lead record and that address's submission history. Repeat with **Erase Personal Data** and confirm both are removed.

== How to test Stage 13 ==

1. Decode a list on the front end (before giving an email) that includes a couple of ingredients not in the library. Confirm on the Leads screen — after completing the gate in the same session — that the submission is attached to your new lead (its history shows the list).
2. Confirm a submission made before the gate is attached to the lead once the gate is completed (nothing orphaned).
3. Open **Ingredient Decoder → Unknown ingredients**. Confirm the unmatched tokens are listed, most-submitted first, with the appearance count and first-seen date.
4. Decode the same unknown token again and confirm its count goes up (not a duplicate row).
5. Dismiss a rubbish token and confirm it leaves the queue.
6. Add `define( 'ILD_ANTHROPIC_API_KEY', '...' );` to wp-config.php. Confirm the **Draft entry** button appears. Draft a real token and confirm a new ingredient is created in **needs-review** (not published), with the fields populated, and that the review queue link opens it.
7. Without the constant defined, confirm the draft button is hidden and a note explains why.
8. Open **Ingredient Decoder → Review Queue** and confirm entries drafted from the most-requested tokens appear at the top (ordered by demand, then alphabetically).
9. Delete a lead and confirm its submissions are removed from the submissions table (no orphan rows).

== How to test Stage 14 ==

1. In **Settings → Limits & cost**, set the analyses-per-hour low (say 3). Analyse a few different lists quickly and confirm the fourth is refused with a calm "going a little fast" message.
2. Set the photo-uploads-per-hour lower still and confirm photo uploads are refused sooner than analyses.
3. Set the daily cap to 1, read one photo (or draft one entry), then try another money-costing action and confirm it's refused gracefully with the "reached our daily limit" message.
4. Decode the same list twice; confirm the second is served instantly (it's cached). Edit or save any ingredient, decode the same list again, and confirm the result now reflects the change (the cache was invalidated).
5. Open **Dashboard** and confirm the Ingredient Decoder panel shows submissions this week, leads this week, the top unmatched ingredients, the library counts by status, and today's paid-API usage against the cap.
6. Confirm nothing in the options table or transients holds a raw IP address (the rate-limit keys are hashed).

== How to test Stage 15 ==

1. With no connector add-on installed, complete the front-end gate with consent and confirm the lead still appears in the Leads screen (captured locally with nothing to push to).
2. Install and activate the **Ingredient List Decoder — Campaign Monitor** add-on. Under **Ingredient Decoder → Settings → Campaign Monitor**, enter a valid API key and list ID and click **Test connection**; confirm it reports success, and that a bad key reports the service's error.
3. Complete the gate again with consent and confirm the lead reaches the Campaign Monitor list, with the source, consent date and tool name set as custom fields.
4. Complete the gate without consent (or for an unsubscribed address) and confirm nothing is pushed.
5. Enter a wrong list ID, complete the gate, and confirm the lead lands in **Leads → Failed sync** with the provider's reason — and that Retry syncs it once the list ID is corrected.

== Changelog ==

= 1.6.14 =
* The photo transcription box is now tidied before you check it. On top of the label-strip and line-join from 1.6.13, a read from a photo now also: drops obvious OCR noise ("N)", "4 : |", "| on" and stray fragments glued to a name), removes a bracketed common name or description that follows an ingredient — "Coco-Glucoside (Plant based surfactant)" becomes "Coco-Glucoside", "Aqua (Water)" becomes "Aqua" — while keeping a leading common name like "(Jojoba) Seed Oil", and preserves colour-index codes (CI 77491). The cleaned list is shown in the verification box for you to confirm or correct.

= 1.6.13 =
* Cleaner reading of pasted and photographed lists. A leading "INGREDIENTS:" (or "Active/Inactive Ingredients:") label — and any heading or OCR noise before it — is now removed, so the list starts at the first real ingredient. And when commas or semicolons separate the ingredients, a name split across two lines (common when a tall label is photographed) is rejoined into one ingredient instead of being read as two. Lists that are genuinely one-per-line (no commas) are left as they are.

= 1.6.12 =
* A returning visitor who has already given consent on this device is no longer asked to tick the box again. When this browser remembers both the email and a prior opt-in, the consent box is replaced by a short "You're opted in — unsubscribe any time" line and the send button is ready to use. Consent is still sent and recorded server-side on every send, so the audit trail continues. Entering a different address is treated as a fresh opt-in and brings the consent box back. The opt-in is remembered only in the browser (localStorage), never in a cookie.

= 1.6.11 =
* A returning visitor now sees which address their copy will go to. When this browser remembers an email (from a previous send), the form shows "We'll send it to name@email.com" with a "Use a different email" text link that reveals the field to change it. The address is kept only in the browser (localStorage), never in a cookie, so nothing about the visitor travels with the page.
* The send button is now a clear, bold, solid button so it's easy to spot, with a distinct disabled state until consent is ticked.

= 1.6.10 =
* The email form is now shown beneath every reading by default, so a visitor can always send the current reading to their inbox — even one who has emailed themselves before. Previously the form was hidden for anyone who had already given an address on that device (a first-party cookie), which also removed their ability to email a later reading. A new "Always offer the email form" setting (General, on by default) controls this; turn it off to restore the old "hide after first submission" behaviour.

= 1.6.9 =
* Fixed the "How this formula is built" heading sitting slightly indented from the "Every ingredient, in order" heading below it. The heading's empty (optional) icon slot left a stray space in front of the text; the markup is now on one line so the heading lines up with the rest of the reading.
* Added a "Point spacing (margin)" control to the summary body (E · Summary block), so the gap around each summary line can be set.

= 1.6.8 =
* The "How this formula is built" summary heading gains margin and padding controls, alongside its existing typography and colour, in the "E · Summary block" section.

= 1.6.7 =
* The "Transcribed ingredients" label in the photo verification step is now styleable. It had no controls of its own; it now has typography, colour, margin and padding, added to the "C · Verification" section (between the notice and the transcription field controls).

= 1.6.6 =
* The "Every ingredient, in order" heading is now styleable. It had no controls of its own; it now has typography, colour, margin and padding controls, added at the top of the "F · Ingredient rows" section in the Elementor widget.

= 1.6.5 =
* The photo route now matches the paste box. The "Drag a photo… / choose one below" instruction and the file-size hint sit beneath the "Or read it from a photo" heading, outside the dashed drop box (which now holds just the buttons), mirroring the paste box's heading-and-intro. In the two-column desktop layout the drop box and the paste box line up along their tops.
* The up-front "Add your email…" line has been removed from the form. The reading always appears first, and the optional "email me a copy" form (with that wording) sits beneath it — so the email ask is only ever shown once, after the results.
* Field headings gain margin and padding controls. Alongside the per-heading typography and colour from 1.6.3, the shared "Field labels — all" and each per-heading override now carry responsive margin and padding, so each heading's spacing can be set on its own.

= 1.6.4 =
* The reading now has its own width. A new "Reading width (results)" control (K · Global) sets the width of the summary, ingredients and read-next block and centres it beneath the form — so the form can stay wide while the reading is kept to a comfortable measure. The overall "Maximum width (whole tool)" control is clearer about being the reason a full-width container can still look narrow (set it to 100% for true full width), and its pixel range now goes up to 1920.

= 1.6.3 =
* A desktop two-column form layout. A new "Desktop layout" control in the Elementor widget (A · Input & form) places the paste box on the left with the photo upload and product-name fields stacked on the right, the verify step and the button running full width beneath — or keeps the single stacked column. Tablet and mobile are always a single column, so it never cramps on a narrow screen. The shortcode takes a matching layout="one|two" attribute.
* Each field heading can now be styled on its own. Alongside the shared "Field labels — all" controls, there are per-heading typography and colour overrides for the "Ingredient list", "Or read it from a photo" and "Product name" headings — so, for example, the Product name heading can be made smaller than the others.

= 1.6.2 =
* Chemical names with a comma in their numbering are no longer split in two. A name like 1,2-Hexanediol or 1,3-Propanediol used to break at its comma into "1" ("we couldn't read this one") and "2-Hexanediol" ("did you mean 1,2-Hexanediol?"). A comma sitting directly between two digits is now kept as part of the name, so it reads as one ingredient and matches. Ordinary separators (a comma followed by a space, as in "Glycerin, Water") are unaffected.
* A "did you mean…?" row now names the suggested ingredient at the top of its Detail panel, so it is clear the role, family and description shown belong to the suggested name rather than the one that was typed.

= 1.6.1 =
* Slash-joined ingredient names now match. A single ingredient printed with all its names slashed together — Aqua/Water/Eau, Parfum/Fragrance, Water/Aqua — used to come back as "not in the library" because the matcher only tried the whole string. It now also tries each name in turn, so the token resolves to whichever one the library holds and the row shows your entry. It stays one ingredient (one lot of water is still one line), matching is order-independent, and bracketed forms like "Aqua (Water)" keep working.

= 1.6.0 =
* Automatic drafting of unknown ingredients. A new "Automatic drafting" settings section lets Claude fill the library on its own: a scheduled background job drafts the most-requested unknown ingredients (those pasted at least a set number of times — three by default) into entries, then clears them from the Unknown ingredients queue. You choose how many are drafted per run and the daily paid-request cap in "Limits & cost" still applies, so cost stays predictable.
* Confident entries can publish straight away. With the status set to "Publish", the drafter asks Claude to identify the ingredient, double-check every field, and report its confidence; only high-confidence entries go live, while anything shakier is held in needs-review for you to check, and anything Claude does not recognise as a real ingredient is never created (the token is dismissed instead). Each drafted entry records the model's confidence and whether it was auto-published.
* The Anthropic API key is now shared. Drafting uses the same "Anthropic API key" setting as photo transcription (the wp-config ILD_ANTHROPIC_API_KEY constant still wins when defined), so one key serves both.
* The two paid features switch on independently. A new "Use the paid AI reading for photos" toggle turns the image-transcription API call on or off on its own — so you can leave automatic drafting running while turning off token spend on hard-to-read photo uploads, or vice versa.

= 1.5.3 =
* Fixed the real cause of a "did you mean…?" row showing empty role and family, an empty Detail panel, and no "Apply to ingredient list" link even after updating: a decoded result is cached as a pre-built view, and the cache key did not include the plugin version, so a result cached by an earlier version was replayed through the new templates with its new pieces missing. The plugin version is now part of the cache key, so every update retires all cached results at once — the same way saving an ingredient already does. The breakdown template also renders defensively now, so a mismatched cache can never again show an empty panel or an invisible link.

= 1.5.2 =
* Hardened a suggestion's role and family display: an entry still being built, with a stray blank role slug, now shows a dash rather than an empty "Role:". (Version bumped so the front-end script and stylesheet are re-fetched past any page or browser cache.)

= 1.5.1 =
* A "Did you mean…?" suggestion now shows the matched library entry in full — its role, family and the Detail expander — exactly as a recognised ingredient does, so a near-miss is as useful as a hit. Beneath the suggestion sits an "Apply to ingredient list" link: one tap swaps the mistyped name for the suggested INCI name in the box and reads the formula again, so a typo is corrected without retyping the list.

= 1.5.0 =
* The email form is no longer a gate. The full reading — summary, every ingredient in order, and the read-next block — now shows on screen straight away for everyone. Beneath it, an optional "email me a copy" form still captures the address and consent and sends the branded email so the visitor can keep their reading; on completion it shows a short confirmation in place of the form. No email is needed to read a formula.

= 1.4.4 =
* Fixed the loading spinner ("Reading the formula…") and the error box showing permanently, even before anything was submitted. Those states are markup that the script reveals by removing the `hidden` attribute, but the stylesheet had no guard to keep `hidden` winning over the `display` rules, so both showed all the time (the error text was just the default in the markup, not a real failure). A `[hidden]` guard now keeps them hidden until they are actually needed.
* Reading a list — a public, read-only action — no longer hard-fails on a stale or missing security nonce, so aggressive page caches (WP Rocket, LiteSpeed) and JavaScript optimisers can never block a reading. The honeypot and the per-IP rate limit still guard the endpoint, and the fresh-nonce fetch from 1.4.3 remains the primary path.

= 1.4.3 =
* The front-end tool is now cache-proof. On a site with full-page caching (LiteSpeed, WP Rocket, Cloudflare) the security nonce baked into the cached page could be hours or months stale, so every submission failed with "something went wrong". The script now fetches a live nonce from a tiny never-cached endpoint on load and uses it for the reading, the email gate and the photo upload, so the tool works regardless of how long the page was cached. (If a page must still be excluded from cache, the stale-nonce message from 1.4.2 makes that obvious.)

= 1.4.2 =
* The Elementor widget now styles the two photo-upload buttons ("Choose a photo" and "Take a photo") — a full button control set under B · Photo upload, which had been missing.
* The front-end reader handles a stale security token distinctly: a cached page with an expired nonce now shows a "please refresh the page" message instead of the generic error, which is the usual cause of the tool working in the admin but not on a cached front-end page. Any other unexpected error during a reading is caught, shown calmly, and logged when WP_DEBUG is on, rather than surfacing as an opaque failure.

= 1.4.1 =
* The one per cent line now weighs marker confidence. A single strong marker places the line on its own; a moderate marker (an unrated one counts as moderate) places it only when a second marker of either level corroborates it further down; a lone moderate marker with nothing to corroborate it is reported as undetermined rather than placed. The line logic never runs across the shade block separated out by the parser.

= 1.4.0 =
* Parser reworked. Bracketed content is now kept rather than stripped, because the brackets usually carry the common name and keeping them helps matching (the matcher retries a bracketed token with the parenthetical removed, so both "Aqua (Water)" and a stored "…(Shea) Butter" INCI resolve). The shade declaration — everything after "may contain", "+/-" or "±" — is separated out and never passed to the concentration-ordering logic, and returned as its own `shade` element. Individual CI colourants in that block collapse to a single "contains colourants" flag rather than being listed, with Titanium Dioxide and Zinc Oxide kept as full entries (by name or CI number) since they double as UV filters and opacifiers. The Test Parser screen shows the shade block.

= 1.3.1 =
* CSV importer/exporter now carries the marker_confidence and category columns, accepting either the stored value or the human label (marker confidence is kept only where the below-1% marker is set). Create-versus-update is decided on the normalised INCI key, and when one file holds the same key more than once the last occurrence wins while each earlier one is skipped with the row number that superseded it.

= 1.3.0 =
* Duplicate prevention rebuilt to cover every route that creates or renames an ingredient, matching on a normalised INCI key rather than the raw name. The key (lower-case, whitespace collapsed, edge punctuation stripped, hyphen/en-dash folded) is stored in a new table with a UNIQUE index, so the database refuses a duplicate even when two saves race past the PHP check. The editor now checks as you type: an exact key match blocks the save and links to the existing entry; an alias match warns without blocking; and a fuzzy near-match warns but always allows the save (Ceramide NP vs Ceramide AP). Adds the ild_ingredient_keys table (schema version 2; existing entries are backfilled on upgrade).

= 1.2.0 =
* Data layer expanded for colour cosmetics. The role vocabulary grows to 36 roles (original skincare slugs unchanged, so no saved data is orphaned). New ingredient fields: marker confidence (strong / moderate, only used where the below-1% marker is ticked) and category (Skincare / Colour / Both, for filtering only — the engine never reads it). Three Ingredient Family terms added: Pigments and fillers, Silicones, Surfactants. The library list screen gains Category and 1% marker columns, and marker-confidence and category filters. (Reactivate the plugin to seed the three new family terms on an existing install.)

= 1.1.0 =
* Photo reading is now free by default. The prepared photo is read in the visitor's own browser (Tesseract.js, loaded on demand) with no API key and no upload — the image never leaves the device. When an Anthropic API key is set, the verification step also offers a "Read it more accurately" button (the paid AI reading), and a free reading that finds nothing falls back to it automatically. A new "Read the list from a photo" switch (on by default) turns the whole photo feature on or off; the API key is now optional. The reader script URL and language are filterable (ild_ocr_engine_url, ild_ocr_language).

= 1.0.0 =
* First stable release. All sixteen build stages of the brief are complete: the ingredient library and taxonomies, CSV import/export, the review queue, the decoding engine and findings, the read-next block, the shortcode and the native Elementor widget, photo transcription, the email gate and branded result email, the leads and submissions admin, the unknown-ingredient queue with AI drafting, rate limiting, caching and the dashboard, and the provider-agnostic email-connector interface. The version numbering during the build tracked the stage (0.1.0–0.15.0); this release marks the finished plugin. No functional change from 0.15.0.

= 0.15.0 =
* Stage 15: the email-connector interface. The core now defines a single provider-agnostic PHP interface (ILD_Email_Connector) and owns the sync queue — deciding when to push a captured lead, honouring consent, retrying with a growing backoff, and recording each outcome against the lead so a rejection shows in the Leads → Failed sync view. Leads are captured locally whether or not any connector is active. The Campaign Monitor connector ships as a separate add-on plugin built against this interface.

= 0.14.0 =
* Stage 14: rate limiting, caching and the dashboard. Configurable per-IP hourly limits on analyses and (tighter) on photo uploads, and a hard site-wide daily cap on money-costing requests (photo reads and AI drafts), all with graceful messages rather than errors. Counters are keyed on a salted hash of the address — no IP stored. The complete result is cached on a hash of the normalised list and invalidated whenever an ingredient is saved or deleted. A dashboard panel shows submissions and leads this week, top unmatched ingredients, library size by status, and paid-API usage against the daily cap. Adds a Limits & cost settings section.

= 0.13.0 =
* Stage 13: submission storage and the unknown-ingredient queue. A custom submissions table (normalised list, findings summary, time, product name, lead ID; indexed on lead ID and date) with pre-gate rows attached to the lead on gate completion in the same session, and deleted with the lead. A second custom table queues unmatched tokens by appearance count with no lead reference. An Unknown ingredients screen lists tokens by frequency with a dismiss action and an AI draft-entry button that creates a needs-review ingredient against the section-4 fields — never published automatically. The drafting key is read from the ILD_ANTHROPIC_API_KEY wp-config constant, not the options table. The token frequency now orders the Stage 3 review queue.

= 0.12.0 =
* Stage 12: the leads admin screen. A custom table of every captured address — date, consent state, the consent wording shown, source page, submission history (read from the submissions store by lead ID), and connector sync status — with date-range and sync filters, address search, CSV export of the filtered set, and per-record delete. A failed-sync view lists connector rejections with a retry action. Deleting a lead deletes its submissions in the same operation. Registers WordPress personal-data exporter and eraser hooks so a privacy request covers the lead record and its submission history. Adds a lead-linked submissions store (extended in the next stage).

= 0.11.0 =
* Stage 11: the branded result email. A table-based HTML email built from the reading, with its CSS inlined and a plain-text alternative generated alongside — logo, editable intro, the summary, the full (flattened) ingredient breakdown, read-next articles with thumbnails, and a footer with the privacy link and a working unsubscribe link on every send. A Result email settings section themes all of it (logo, widths, radius, colours, type, button, font stack, intro/sign-off/footer), with a live admin preview and a test send. Sent through wp_mail() so the site's transactional transport is used. The email is sent automatically when the gate is completed. Web-safe font stack by design.

= 0.10.0 =
* Stage 10: the email gate. The summary shows immediately; the ingredient breakdown and read-next block are held behind an email form (and never sent to the page until it is completed). One consent checkbox, unticked by default, covers the result email and marketing from Apotheca®, with the submit button disabled until it is ticked and the reason shown. The exchange wording also appears up front near the input. On submission a first-party cookie (default twelve months) skips the gate next time, and the address, timestamp, consent state, exact wording shown, and source page are stored as a private lead. Honeypot and nonce throughout. Adds the section-11 group J style controls, editable exchange/consent wording (privacy link and unsubscribe line fixed), and an Email gate preview state.

= 0.9.0 =
* Stage 9: the read-next block. Beneath the result, it collects the Skin Topic and Ingredient Family terms of the ingredients that generated the findings, weights topic above family, and shows up to three published posts that share those terms — ordered by shared-term count then recency, excluding the current page. Renders nothing when nothing shares a term (never a fallback to recent or popular). Cached per term-set and cleared on any post or ingredient save. Adds the section-11 group I style controls and sample cards in the editor preview.

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
