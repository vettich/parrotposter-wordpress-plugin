=== ParrotPoster - Auto Post to Social Media ===
Contributors: parrotposter
Tags: autopost, social media, telegram, facebook, instagram
Requires at least: 5.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.1
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Auto post or selective post of news and products to Facebook, Instagram, Telegram, VK, Max, and OK.

== Description ==

**Autoposting of news and products from the site to social networks - fast and convenient!**
Автопостинг новостей и товаров с сайта в социальные сети – быстро и удобно!

ParrotPoster is a complete WordPress plugin for auto publishing and selective posting. To use it, register and connect your site to the ParrotPoster cloud service (required). The plugin itself is fully functional once connected; the paid **service** includes a 14-day trial.

= Supported social networks =
* Facebook
* Instagram
* Telegram
* VKontakte
* Max
* Odnoklassniki

= Features and benefits =

**Quick and easy addition of social media accounts 🚀**
Connect accounts through the service — no need to configure your own apps inside each social network.

**Pipeline automation and legacy templates 👍**
Set up pipeline-based automation for new sites, or keep using legacy autoposting templates until you migrate. Existing templates keep working after the update.

**Selecting certain accounts when posting ✅**
Choose only the social media accounts you need when publishing products or news.

**Auto publishing to multiple accounts 🤩**
Publish to several social network accounts in one action.

**Unlimited posts 📍**
The plugin has no limit on the number of posts — publish as many as you need.

**Flexible automation conditions 🔧**
Filter by post type, categories, titles, authors, tags, product price, selected accounts, and publishing time (immediate or delayed).

**Customizable post text templates 📄**
Include title, excerpt, first paragraph, full content, line breaks, product prices and sizes, currency, weight, link, tags, and more.

**Automatically publish when content is added 📚**
When automation is configured, the plugin publishes news or products according to your rules.

**Selective posting from the post editor 📌**
On the edit screen of a news item or product, use "Post to social networks" to customize the post and publish manually.

**Publish pictures, headlines, text, links, tags 📝**
Publish the featured image, content or gallery images, titles, excerpt or full text, links, tags, and more.

**Convert tags into hashtags #️⃣**
Turn WordPress tags into hashtags for cleaner social posts.

**Customize publishing time 🕘**
Publish immediately, with a delay, or at a scheduled time.

**Adding UTM tags to links 🔗**
Add UTM parameters (utm_source, utm_medium, utm_campaign, utm_term, utm_content) and track traffic in analytics.

**Excluding duplicates 😉**
Automatically skip duplicate news and products for a given automation rule.

**View publication statuses 💡**
See results, publication times, and links to posts on social networks.

= Supported languages =
* Russian
* English

✉️ Technical support: [support@parrotposter.com](mailto:support@parrotposter.com)
🌐 Service site: [parrotposter.com](https://parrotposter.com)

== External services ==

ParrotPoster connects your WordPress site to the cloud service at [parrotposter.com](https://parrotposter.com) (with a fallback mirror at [mirror-pl.parrotposter.com](https://mirror-pl.parrotposter.com)).

After you connect the site, the plugin sends post content, media, post metadata, and your site domain to the service so publications can be created and tracked. The plugin admin UI is loaded from the service inside an embedded frame (iframe).

* Terms of service: [parrotposter.com/site/legal/agreement](https://parrotposter.com/site/legal/agreement)
* Privacy policy: [parrotposter.com/site/legal/confidential](https://parrotposter.com/site/legal/confidential)

== Installation ==

* Install the plugin
* Activate the plugin under "Plugins" in the WordPress menu
* Register and connect your site to the ParrotPoster cloud service (required)

== Frequently Asked Questions ==

= How many posts per day can I publish? =
No limits, but in the social network VKontakte you can publish no more than 50 posts per day, and in other social networks, we recommend publishing no more than 15-25 posts per day, so that the social network does not block the account.

= How long is the trial version and can I change the tariff? =
When you register for the plugin, you get 14 days of free use with the option to add 3 accounts to test the functionality. If you need more accounts - you can change the tariff in the "Tariffs" section.

= How many social network accounts can I add? =
It depends on the chosen tariff. The trial version has 3 accounts, on paid tariffs from 5 to 22 accounts. If you need to add more than 22 accounts, send us an email at support@parrotposter.com.

= Why can I not add a social account? =
* You are not an administrator of the group or page, or you were not granted the required access rights
* OAuth or token credentials were rejected by the social network
* Telegram Bot Token or channel/group link was specified incorrectly
* Try reconnecting the account from the ParrotPoster accounts screen; if the problem persists, contact support

= Are there any plans to add new social networks? =
Yes, we plan to do this in the near future.

= Have not found an answer to your question or do you have a suggestion on how to improve the plugin? =
Email us at [support@parrotposter.com](mailto:support@parrotposter.com)


== Screenshots ==
1. Sign up for the plugin
2. Social network accounts
3. List of automations
4. Create automation
5. List posts
6. Post results

== Upgrade Notice ==

= 2.0.1 =
Sites that updated to 2.0.0 without appearing under Connected sites will reconnect automatically. After switching the site to HTTPS, reconnect from Settings so ParrotPoster stores the new address.

= 2.0.0 =
After updating from 1.1.x, your legacy autoposting scheduler keeps working until you switch to the new pipeline templates. No action is required for the plugin to continue publishing.

== Changelog ==

= 2.0.1 =
* Automatically retry connecting the site to ParrotPoster if the 2.0.0 upgrade did not finish the handshake
* Connecting the site no longer fails when WordPress cannot call its own public URL (HTTPS redirects, reverse proxies)
* Warn on the settings page if the site is on HTTP or the address stored in ParrotPoster is out of date
* Reconnect updates the site address ParrotPoster uses after enabling HTTPS or changing the domain
* An intentional disconnect is not undone by the automatic reconnect retry

= 2.0.0 =
* Pipeline-based automation for configuring when and where WordPress content is published
* Migrate existing legacy autoposting templates to pipelines without losing your rules
* Site binding flow to connect WordPress securely to your ParrotPoster account
* Publish to social networks directly from the WordPress post editor
* Embedded ParrotPoster service UI in the plugin admin for accounts, pipelines, and settings

= 1.1.5 =
* Fixed `{content_first_paragraph}`: walks content in document order, skips image-only paragraphs and decorative separators (`-----`), supports list items and classic HTML blocks

= 1.1.4 =
* Added `{content_first_paragraph}` template macro: first paragraph from post content (Gutenberg paragraph block, first `<p>`, or plain-text fallback)

= 1.1.3 =
* Shared lazy media upload cache when several autopost templates publish one WordPress post (no duplicate uploads per attachment)
* Retry file upload to ParrotPoster; fallback to image URLs when upload fails
* Fix image URLs on post update; rate-limit guard distinguishes templates (wp_autoposting_id)

= 1.1.2 =
* Fixed local sync queue stuck in pending when WordPress timezone differs from MySQL: queue datetimes are stored and compared in UTC, with a one-time migration for existing pending rows and an automatic post-queue wake after migration
* Local sync queue HTTP processing uses a dynamic batch: one item at a time, up to 10 items or 5 seconds per callback (scheduler or admin “Process now”)

= 1.1.1 =
* Added an admin notice on Posts and Scheduler when the local sync queue has pending tasks, with a View button that opens a modal listing queue items and linked WordPress posts

= 1.1.0 =
* Reworked the plugin admin experience around an embedded ParrotPoster interface with short-lived session tokens, iframe messaging, and a safer login flow after authorization
* Reworked autoposting scheduling: tasks are queued locally and processed asynchronously, and the scheduler reacts when posts are published, updated, trashed, or permanently deleted
* When a published WordPress post already linked to ParrotPoster is edited, the plugin updates existing social publications where appropriate instead of only creating new ones
* Improved reliability when calling the ParrotPoster API (automatic fallback across domains and clearer behavior when the service is temporarily unavailable)
* Optimized duplicate checks and last-publication-time lookups when publishing via multiple autoposting templates
* Added WordPress AJAX nonce verification for admin requests and restricted sensitive plugin actions to administrators only

= 1.0.16 =
* Fixed the publication of news with a delay

= 1.0.15 =
* Fixed delayed post publication

= 1.0.14 =
* Added display of last post time by template

= 1.0.13 =
* Added the ability to publish posts via already created auto-publish templates
* Fixed publishing images from cloud storage (like s3)

= 1.0.11 =
* Fixed parse images from content, when a third-party plugin adding CDN is enabled

= 1.0.9 =
* Added support shortcodes in post text/tags/link

= 1.0.5 =
* Fixed the publication of products without review

= 1.0.4 =
* Improved truncate excerpt of post

= 1.0.3 =
* Added support mobile resolution

= 1.0.2 =
* Fixed ui multiselect field in custom conditions

= 1.0.1 =
* Fixed jquery-ui loading
* Updated translate files

= 1.0.0 =
* Plugin created
