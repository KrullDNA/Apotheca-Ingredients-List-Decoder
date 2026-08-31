=== Ingredient List Decoder — Campaign Monitor ===
Contributors: kdna
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pushes consented Ingredient List Decoder leads to a Campaign Monitor list.

== Description ==

An add-on for the **Ingredient List Decoder** core plugin. It implements the core's email-connector interface, so the core does all the work — capturing leads locally, holding the queue, deciding when to push, honouring consent, retrying with backoff, and recording every outcome. This add-on only knows how to talk to Campaign Monitor.

For each consented lead it pushes the name (where given), the email, and — as custom fields — the source page, the consent date, and the tool that captured it. The tool field lets you segment these leads apart from Founding Faces members.

= Requirements =

* The Ingredient List Decoder core plugin, active. Without it, this add-on stays inert and shows a notice.
* An API key and a list ID, set under **Ingredient Decoder → Settings → Campaign Monitor**.
* Three custom fields on the Campaign Monitor list, with the keys **Source**, **ConsentDate** and **Tool**. Create these in Campaign Monitor before pushing, or the service will reject the subscriber (and the rejection will show in the core's failed-sync view).

= How it works =

* Leads are captured by the core whether or not this add-on is installed.
* When a lead consents, the core queues a push to every configured connector. Only consented, non-unsubscribed leads are ever pushed.
* A failed push is retried a few times with a growing backoff. If it still fails, it is left in the core's **Leads → Failed sync** view with the reason, and can be retried by hand.

== Installation ==

1. Install and activate the Ingredient List Decoder core plugin first.
2. Install and activate this add-on.
3. Go to **Ingredient Decoder → Settings → Campaign Monitor**, enter your API key and list ID, and click **Test connection**.
4. Create the **Source**, **ConsentDate** and **Tool** custom fields on the list in Campaign Monitor.

== How to test ==

1. With the core active, activate this add-on and confirm no "needs the core" notice appears.
2. Enter an API key and list ID and click **Test connection**; confirm a valid pair reports "Connected" and a bad key reports the service's error.
3. Complete the front-end gate with consent, then check the Campaign Monitor list for the subscriber, with Source, ConsentDate and Tool set.
4. Enter a wrong list ID, complete the gate, and confirm the lead appears in **Leads → Failed sync** with the reason — and that Retry works once the list ID is corrected.
5. Deactivate the core plugin and confirm this add-on shows the "needs the core" notice and does nothing else.

== Changelog ==

= 1.0.0 =
* First release: the Campaign Monitor connector for the Ingredient List Decoder, implementing the core email-connector interface. API key and list ID in settings, a test-connection button, name/email/source/consent-date pushed with the tool as a custom field, consent-only pushes, retry with backoff, and every failure surfaced in the core's failed-sync view.
