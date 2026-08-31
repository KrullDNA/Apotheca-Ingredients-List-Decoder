=== Ingredient List Decoder — Klaviyo ===
Contributors: kdna
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pushes consented Ingredient List Decoder leads to a Klaviyo list, directly via the API.

== Description ==

An add-on for the **Ingredient List Decoder** core plugin. It implements the core's email-connector interface, so the core does all the work — capturing leads locally, holding the queue, deciding when to push, honouring consent, retrying with backoff, and recording every outcome. This add-on only knows how to talk to Klaviyo.

For each consented lead it pushes, directly through the Klaviyo API (never a CSV import):

* the name, where given;
* the email;
* the source page, set as **both a profile property and a tag** — matching the existing Founding Faces convention of keying segments off both;
* the consent date and the capturing tool, as profile properties, so these leads can be segmented apart from Founding Faces members.

The profile is upserted and then subscribed to the configured list with email marketing consent recorded.

= A note on Klaviyo "tags" =

Klaviyo has no native per-profile tags — its Tags feature tags lists, segments and flows, not people. The "tag" is therefore carried as a multi-value profile property (named **Tags**), which segments key off exactly as they would a native tag. This matches how the Founding Faces setup already works.

= Running alongside Campaign Monitor =

This add-on and the Campaign Monitor add-on can both be active at the same time — the core pushes a consented lead to every configured connector. In practice only one is expected to be active.

= Requirements =

* The Ingredient List Decoder core plugin, active. Without it, this add-on stays inert and shows a notice.
* A Klaviyo **private API key** and a **list ID**, set under **Ingredient Decoder → Settings → Klaviyo**.
* Custom profile properties will be created by Klaviyo on first write; no manual setup is needed for Source, Tags, ConsentDate or Tool.

= How it works =

* Leads are captured by the core whether or not this add-on is installed.
* When a lead consents, the core queues a push to every configured connector. Only consented, non-unsubscribed leads are ever pushed.
* A failed push is retried a few times with a growing backoff. If it still fails, it is left in the core's **Leads → Failed sync** view with the reason, and can be retried by hand.

== Installation ==

1. Install and activate the Ingredient List Decoder core plugin first.
2. Install and activate this add-on.
3. Go to **Ingredient Decoder → Settings → Klaviyo**, enter your private API key and list ID, and click **Test connection**.

== How to test ==

1. With the core active, activate this add-on and confirm no "needs the core" notice appears.
2. Enter a private API key and list ID and click **Test connection**; confirm a valid pair reports "Connected" and a bad key reports the service's error.
3. Complete the front-end gate with consent, then check the Klaviyo list for the profile — subscribed, with the Source property, the source in the Tags property, and ConsentDate and Tool set.
4. Enter a wrong list ID, complete the gate, and confirm the lead appears in **Leads → Failed sync** with the reason — and that Retry works once the list ID is corrected.
5. Deactivate the core plugin and confirm this add-on shows the "needs the core" notice and does nothing else.

== Changelog ==

= 1.0.0 =
* First release: the Klaviyo connector for the Ingredient List Decoder, implementing the core email-connector interface. Private API key and list ID in settings, a test-connection button, name/email/source/consent-date pushed directly via the API with the source as both a profile property and a tag and the tool as a property, consent-only pushes, retry with backoff, and every failure surfaced in the core's failed-sync view.
