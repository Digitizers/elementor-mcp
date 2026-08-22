=== MCP Tools for Elementor ===
Contributors: mianshahzadraza
Tags: elementor, mcp, ai, page-builder, automation
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 1.32.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Extends the WordPress MCP Adapter to expose Elementor data, widgets, and page design tools as MCP tools for AI agents.

== Description ==

MCP Tools for Elementor bridges the gap between AI tools and Elementor page design. It extends the official WordPress MCP Adapter to expose up to 118 MCP (Model Context Protocol) tools that let AI agents like Claude, Cursor, and other MCP-compatible clients create and manipulate Elementor page designs programmatically.

Tool counts scale with your environment: 61 tools on a free Elementor install, 100 with Elementor Pro, 105 with Pro + WooCommerce, and 13 additional atomic tools when Elementor 4.0+ is active (74 / 113 / 118 respectively).

**Key Features:**

* **Query & Discovery** — List widgets, inspect page structures, read element settings, browse templates, and view global design tokens.
* **Page Management** — Create pages, update page settings, clear content, import/export templates.
* **Layout Tools** — Add flexbox containers, move/remove/duplicate elements, batch updates, reorder children.
* **Widget Tools** — Universal add/update for any widget, plus 27 free convenience shortcuts, 30 conditional Pro widget tools, and 5 WooCommerce widget tools.
* **Pro Widget Support** — Conditional tools for Elementor Pro widgets (form, posts grid, countdown, price table, flip box, animated headline, call to action, slides, testimonial carousel, price list, gallery, share buttons, table of contents, blockquote, Lottie, hotspot, loop grid/carousel, nested tabs/accordion, portfolio, author box, login, code highlight, reviews, off-canvas, progress tracker, search, and more) that only register when Pro is active.
* **Atomic Elements (Elementor 4.0+)** — 13 dedicated tools for Elementor's new atomic system: flexbox, div-block, heading, paragraph, button, image, svg, youtube, video, divider, plus universal `add-atomic-widget` / `update-atomic-widget` and `detect-elementor-version`.
* **Template Tools** — Save pages or elements as reusable templates, apply templates to pages, theme builder, popups, dynamic tags (Pro).
* **Global Settings** — Update site-wide color palettes and typography presets.
* **Composite Tools** — Build a complete page from a declarative JSON structure in a single call.
* **Stock Images** — Search Openverse for Creative Commons images, sideload into Media Library, add to pages.
* **SVG Icons** — Upload SVG icons from URL or raw markup for use with Elementor icon widgets.
* **Custom Code** — Add custom CSS (element/page level), inject JavaScript, create site-wide code snippets for head/body injection.
* **AI Widget Builder (Pro)** — Let an AI agent design custom Elementor widgets from a structured spec (no hand-written PHP). The plugin compiles the spec into a sandboxed widget that appears in the Elementor panel — 35 control types, optional CSS/JS, with a runtime safety net so a bad widget can never break the editor.
* **Brand Kits** — One-click color + typography kits that re-skin your whole site. 10 bundled kits, free to apply, with backup + restore.
* **Low-tools Mode** — One-click toggle that trims the active tool list to a curated 50-or-so essentials so MCP clients with strict tool caps (Antigravity, Gemini API, etc.) stay under their limits.
* **Sample Prompts** — Ready-to-use landing page blueprints with one-click copy from the admin dashboard.
* **Admin Dashboard** — Dedicated top-level menu with Tools, Connection, Prompts, Brand Kits, Widget Builder, and Changelog tabs. Toggle individual tools on/off, view connection configs for all supported MCP clients, and get help via the built-in Get Support link.

**Requires:**

* WordPress 6.9 or later
* Elementor 3.20 or later (container support required)
* WordPress Abilities API — included in WordPress core 6.9+ (and 7.0)
* WordPress MCP Adapter — bundled with the plugin (no separate install needed; an active standalone MCP Adapter plugin is used instead when present)

**Connection Methods:**

* WP-CLI stdio (recommended for local development)
* Node.js HTTP proxy (for remote sites)
* Direct HTTP (for VS Code MCP extension)

== Installation ==

1. Install and activate [Elementor](https://wordpress.org/plugins/elementor/) (version 3.20+).
2. Upload the `elementor-mcp` folder to `/wp-content/plugins/`.
3. Activate the plugin through the 'Plugins' menu in WordPress. The MCP Adapter is bundled — no separate install is required (WordPress 6.9+/7.0 already includes the Abilities API).
4. Open the new **EMCP Tools** top-level menu, go to the **Connection** tab, and confirm **Activate Abilities API for EMCP** is enabled (on by default) to expose the MCP server.

= WP-CLI Connection (Local) =

Add to your MCP client configuration:

`
{
  "mcpServers": {
    "elementor-mcp": {
      "command": "wp",
      "args": ["mcp-adapter", "serve", "--server=elementor-mcp-server", "--user=admin", "--path=/path/to/wordpress"]
    }
  }
}
`

= Codex Connection =

Add to `~/.codex/config.toml` or `.codex/config.toml`:

`
[mcp_servers.elementor-mcp]
url = "https://your-site.com/wp-json/mcp/elementor-mcp-server"

[mcp_servers.elementor-mcp.http_headers]
"Authorization" = "Basic BASE64_ENCODED_CREDENTIALS"
`

= npx mcp-remote Connection (Local) =

For local development, use `mcp-remote` to bridge your AI client to the WordPress HTTP endpoint:

`
{
  "mcpServers": {
    "elementor-mcp": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "http://localhost:10003/wp-json/mcp/elementor-mcp-server",
        "--header",
        "Authorization: Basic BASE64_ENCODED_CREDENTIALS"
      ]
    }
  }
}
`

Replace `localhost:10003` with your local WordPress address and `BASE64_ENCODED_CREDENTIALS` with your Base64-encoded `username:app-password`.

= HTTP Proxy Connection (Remote) =

1. Create a WordPress Application Password at Users > Profile > Application Passwords.
2. Configure your MCP client with the included Node.js proxy:

`
{
  "mcpServers": {
    "elementor-mcp": {
      "command": "node",
      "args": ["bin/mcp-proxy.mjs"],
      "env": {
        "WP_URL": "https://your-site.com",
        "WP_USERNAME": "admin",
        "WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
`

== Frequently Asked Questions ==

= What is MCP? =

MCP (Model Context Protocol) is an open standard that allows AI tools to interact with external services. This plugin exposes Elementor's page building capabilities as MCP tools.

= Does this plugin work without Elementor Pro? =

Yes. Core widget tools work with free Elementor. Pro widget shortcuts (form, posts grid, countdown, price table, flip box, animated headline) only register when Elementor Pro is active.

= Why does the plugins screen not offer me an update? =

Because the plugin is deactivated. Updates for this plugin come from its own bundled update checker, which runs inside the plugin — and WordPress does not load the code of an inactive plugin, so nothing checks and nothing is offered. Plugins installed from the WordPress.org directory behave differently: there, WordPress itself asks about every installed plugin, active or not.

Activate it and the update offer appears on the next check. While it is inactive it does nothing at all, so being out of date is the least of it.

= Can I disable specific tools? =

Yes. Open the **EMCP Tools** top-level admin menu and use the **Tools** screen to toggle individual tools on or off. If your MCP client has a strict tool cap (e.g. Antigravity's 100-tool limit), flip on **Low-tools mode** at the top of that screen to expose only a curated set of essentials.

= Does this plugin require the WordPress MCP Adapter? =

Yes. The MCP Adapter handles the MCP protocol transport layer. This plugin registers its tools through the Adapter's server infrastructure.

= Is this plugin safe to use on production sites? =

The plugin enforces WordPress capability checks on every tool. Read operations require `edit_posts`, write operations check `edit_post` ownership, and global settings require `manage_options`. All input is sanitized and validated.

== Screenshots ==

1. Tools management page with category-grouped toggles.
2. Connection configuration page with copy-paste configs.

== Changelog ==

= 1.32.0 =
New: operator rules set in Aura (block or warn a page or post, or freeze the whole site) are now enforced on Elementor writes made through this plugin, when SiteAgent 2.10.0+ is installed. A block refuses the write before anything is saved or snapshotted; an approval does not override a rule; previews are unaffected. `server-info` reports whether rules are enforced on this site.

= 1.31.0 =
Fixed (security): the protection added in 1.30.0 did not fully cover a site running this plugin WITHOUT SiteAgent. A write-capable tool is now refused at the permission stage on every site when the caller is not on this plugin's own MCP server.
* 1.30.0 kept write tools out of another MCP server's menu by declaring mcp.type = private, and refused an ungranted foreign write at execute time. The second of those lives in the SiteAgent governance wrapper, which does nothing when SiteAgent is absent — so on a standalone site the metadata was the only protection, and it depended on the other plugin continuing to honour that convention.
* The gate checks that a grant is PRESENT and lets the governance layer verify it once. Verifying in both places would burn the grant's single-use nonce and reject the legitimate call on its own approval. Where no verifier is installed, a foreign write is refused outright rather than accepted on a header nothing can check.
* It wraps rather than replaces: the tool's own capability check still runs, and runs last, so this can only ever deny more than before.
* Dry runs are exempt, as they already are at execute time — a preview-capable tool called with apply falsy writes nothing, so there is nothing to approve.
* Read-only tools are not gated. An assistant on another server answering questions from this plugin's data is the point of the read-only bridge.
* The elementor_mcp_expose_writes_to_foreign_mcp filter still works exactly as documented: a write you open through it stays reachable from other servers, and server-info names the ones you opened.
* server-info reports both guards separately, so a standalone site is described as closed rather than exposed.

= 1.30.0 =
Fixed (security): this plugin's write tools were reachable from any OTHER MCP server installed on the same site — a transport with no approval queue, no audit and no fleet visibility behind it.
* Abilities are registered with WordPress, not with a server, so a second MCP server on the site serves them without asking. Elementor's Angie 1.1.12 ships one at /mcp/angie whose discovery exposes every third-party ability that does not declare a non-tool mcp.type, and whose execute-ability proxy then runs it by name.
* Two guards, covering different sites. At registration, a write tool now declares mcp.type = private, which Angie's listing AND its execution gate both refuse — this is the only protection on a site without SiteAgent, where the governance wrapper leaves every ability untouched. At execution, a governed write arriving on any route other than this plugin's own MCP server must present a valid approval grant, whether or not grant enforcement is switched on; the refusal lands before the tool runs, so an unauthorized create cannot insert a draft first.
* Read tools stay available to other servers on purpose — an assistant answering "what is on this page" is the point of the read-only bridge. Only writes are withheld.
* Two escape hatches, both off by default: elementor_mcp_expose_writes_to_foreign_mcp re-exposes writes, and elementor_mcp_trusted_write_context trusts a named route for operators with a genuine second integration.
* server-info now reports write exposure: whether writes are withheld, which write tools (if any) are left open and why, whether the execution-side check is actually running (it needs SiteAgent), and which other MCP servers are active on the site — including which of them publish these tools directly.

= 1.29.0 =
Fixed: nine correctness and discoverability defects on the atomic (Elementor 4.x) write path, all found while building a real client site. Several were silent — the tool reported success and Elementor dropped the value.
* Fixed: font-family was emitted with the wrong $$type, so no font set through any tool ever applied. Elementor types it as Font_Family_Prop_Type, which does not share String_Prop_Type's key, and the style schema silently drops props that do not match.
* Fixed: the fallback write path never invalidated Elementor's rendered-element cache (_elementor_element_cache). That path is the one taken in non-browser contexts — i.e. how the MCP writes — so a correct write served the pre-change markup until the cache expired.
* Fixed: add-custom-css was a no-op on atomic elements. It wrote the Elementor 3.x settings.custom_css control, which atomic elements never read. It now writes a style variant, base64-encoded, because Elementor decodes that field through base64_decode() and a plain CSS string is dropped.
* Fixed: update-atomic-widget could not change a style at all — it wrote only settings, but size, spacing and appearance live in the styles map. It now accepts the same flat style params as the add-* tools and merges them into the element's base variant, member-wise for composite props so touching one padding side no longer drops the other three.
* Fixed: add-flexbox and add-div-block hid capabilities the engine already had. Borders, radii, widths, gradients and per-side spacing always worked but were never published, so agents concluded atomic containers could not express them. Both tools now publish everything the builders accept, from a single shared source, and tests pin schema and builder together in both directions.
* New: position and box-shadow on atomic elements — the two capabilities that were genuinely missing. The input key is css_position, not position, which the layout tools already use for the insert index. Offsets use logical names (offset_inline_start etc.) because the inline axis follows text direction.
* Fixed: add-nav-menu published menu_name as the menu selector, but that is the widget's accessible label; the SELECT that picks the navigation is menu. On a site with more than one menu the wrong navigation was inserted. menu is now published, carrying the registered slugs as an enum.
* New: server-info — an always-available diagnostic that cannot be switched off. Reports plugin/Elementor/Elementor Pro versions, atomic state, the resolved MCP adapter source and version, whether the server endpoint is on, and registered vs exposed vs suppressed ability counts with the suppressed slugs and the cause of each gap. A fresh install presenting as "zero tools exposed" previously had nothing anywhere to explain it.
* Fixed: replace-system-colors and update-global-colors now describe what they each write and name each other. update-global-colors appends to custom_colors and never touches the four system slots every Global Color picker references, so a brand palette could land while Elementor's stock colour stayed bound to the roles that matter.

= 1.28.0 =
New: GitHub self-updater. Updates for this fork now appear on the normal Plugins and Dashboard > Updates screens, pulled from the repository's tagged GitHub Releases (bundled Plugin Update Checker 5.6).
* Detection is release-only: a pushed version tag never offers an update until its Release is published.
* Every request to GitHub — the update check and the package download, across the release-asset redirect hosts — carries a neutral elementor-mcp/<version> user agent instead of WordPress's default, which would send the site URL to GitHub.
* A Release that attaches a built elementor-mcp*.zip asset is preferred; otherwise the tag's source archive is served.

= 1.27.1 =
Fixed: the Angie bridge never loaded in the Elementor editor — the one surface it exists for. Its bundle was registered on admin_enqueue_scripts, which the editor never fires: that screen is rendered from admin_action_elementor, before admin-header.php, and Elementor then calls remove_all_actions('wp_enqueue_scripts') and rebuilds a front-end style document from its own hooks. The bridge now also rides elementor/editor/after_enqueue_scripts, the hook Angie's own editor integration uses, and guards against a double localize.
* Fixed: the bridge registered through registerServer() with a config carrying no type. The SDK answers that with a warning and hands off to registerLocalServer() without awaiting, so the promise resolved before the registration had begun and a failure inside it could never surface. It now calls registerLocalServer() directly.
* New: window.emcpAngieBridgeDebug — isAngieReady(), registrations(), pending(). A registration that stays queued is otherwise indistinguishable from a successful one: nothing throws, nothing logs, the tools simply never appear. It exposes state, not authority; every tool call still goes through the REST routes, the allowlist, the read-only invariant, and each ability's own permission callback.
* Verified live on WP 6.9 / Elementor 4.2.2 / Angie 1.1.11: Angie discovers the six read-only tools and executes them. The bridge's tool list is independent of the admin Tools toggles: every ability is registered regardless, the toggles only trim the name list handed to the MCP Adapter server, and the bridge resolves its own allowlist directly. Switching tools off there does not empty the bridge.

= 1.27.0 =
New: Whole-tree atomic prop coercion (P3.3). Settings written through any tool are coerced against the widget's own prop schema before the save, so a plain value handed to an atomic prop is stored as the typed envelope Elementor expects instead of silently rendering as nothing.
* Candidate envelopes come only from members the prop declared itself (get_key/get_prop_types/get_shape/get_item_type/aliases); the generic primitive fallback runs only for props that did not describe themselves. Elementor's primitive validation accepts an "empty" enveloped value for a non-required prop before the type/enum check, so guessing a foreign envelope stores a malformed prop that validates and then renders nothing.
* Atomic style wiring: update-element-settings hoists root-level styles/editor_settings, deep-merges instead of replacing, and syncs local class references so a written style is actually referenced from settings.classes. Hoisting requires a positive atomic signal — the e- slug prefix is a convention, not an authority.
* Shared convenience-param mapper (Elementor_MCP_Atomic_Widget_Map): the add-atomic-* tools and build-page now produce byte-identical settings for the same input; build-page previously passed settings through raw and an atomic widget given friendly params came out empty. Props the caller already typed pass through untouched rather than being sanitized to an empty string.
Fixed (security): the attachment alt write (_wp_attachment_image_alt) implied by add-atomic-image/build-page is authorized against the attachment (edit_post on the attachment, not the page) and deferred until the page save succeeds; a media prop the caller typed itself cancels it, since the friendly image_id then names an attachment that is not on the page.
* Upstream-correct media shapes: e-image emits an id-XOR-url image-src with an image-attachment-id envelope and the alt inside src; e-self-hosted-video's source is the video-src shape on 4.x, where a bare url envelope makes Elementor refuse the element outright.
* The SEO content extractor descends through an atomic image's src, and for a media-library image the library's own alt is authoritative — Elementor renders _wp_attachment_image_alt and ignores src.alt, so an empty library alt is the real state.
* Four upstream correctness ports: silent-save verification (a truthy Document::save() that persisted nothing now falls back to direct meta), local style classes re-minted on duplicate, fatal-proof ability registration, and structuredContent normalization for strict MCP clients.

= 1.26.0 =
New: Angie bridge (read-only v1) — an opt-in, off-by-default integration that registers "Aura Design Engine" as an MCP server inside Elementor's Angie assistant via the public @elementor/angie-sdk.
* Exposes exactly six read-only inspection tools (list-widgets, get-widget-schema, get-page-structure, list-pages, list-global-classes, list-variables). No write tools: Angie is an agent, and this cookie/capability transport cannot carry the per-mutation human approval the governed write path requires — a mutating ability reached through the bridge returns bridge_writes_phase_b.
* REST namespace emcp/angie/v1 with a cookie session + X-WP-Nonce, an edit_posts gate on the routes plus each ability's own permission callback on every call. The allowlist is enforced server-side with a read-only invariant, so a filtered-in or drifted mutating ability is rejected at runtime; anything outside the allowlist is a plain 404.
* Admin toggle on the Connection tab, default off, stating exactly what is and is not exposed.

= 1.25.1 =
Changed: De-branded the leftover upstream links. The admin header's "Read the Docs" and "Get Support" buttons pointed at the original author's commercial site (emcp.msrbuilds.com/docs, support.msrbuilds.com), content this fork does not serve; they now point at the fork's own GitHub (README and Issues), as does the bin/ proxy README link. Attribution to the original author @msrbuilds (GPL-3.0) is unchanged.

= 1.25.0 =
New: Global-classes governance (plank 2) — the Class Manager writers (create/update/delete-global-class) now get snapshot-before-write + rollback, closing the last design-token reversal gap.
* In Elementor 4.x a global class is its own CPT post (e_global_class), indexed by kit meta, and a delete cascades (rewrites every page whose _elementor_data referenced it). Reversing that is a multi-post transaction — kit index + sync-to-v3 map + class CPT posts + reverse usage index + cascade pages — captured in one snapshot_posts() call (SiteAgent's multi-post primitive) for a single-restore rollback. Each writer declares a global-classes governance scope; a lazy before_global_classes_write() at the repository write site snapshots the whole transaction. apply-global-class is unchanged (writes page data, already page-governed).
* Fail-closed on every un-reversible edge: no active kit, unresolvable cascade, a SiteAgent too old to have snapshot_posts, or an in-use class whose reverse index is empty (its pages found only via the multi-valued used_classes fallback the single-valued snapshot cannot restore). Also corrected the delete-global-class description (Elementor does cascade page rewrites — verified against 4.1.4). Soft dependency on SiteAgent's snapshot engine.

= 1.24.0 =
New: Kit-scoped governance (design-token writes get snapshot-before-write + rollback).
* The system-kit + global-palette writers (replace-system-colors/typography, update-global-colors/typography) and v4 Variables writers (create/edit/delete/restore-variable) act on the active kit post, not a page, so they never hit the page write-site and were ungoverned for reversal. They now declare a kit governance scope, and their write sites call before_kit_write() right before mutating the kit (the same lazy, write-site pattern page writes use) — snapshotting the kit's design-token meta (_elementor_page_settings, _elementor_global_variables) via SiteAgent's snapshot_meta and rolling back on failure. Snapshotting at the write site (not eagerly) means a tool that fails input validation never snapshots, so a failed run never reverts a concurrent, unrelated kit change. A first-write create is fully reverted (restore deletes absent-at-capture keys).
* Fail-closed: no active kit or a snapshot failure refuses the write (governance_snapshot_failed). Grants (opt-in) checked before the snapshot. No render check (a kit affects the whole site). Soft dependency on SiteAgent's snapshot engine. Not covered yet: global classes (separate CPT — plank 2) and interactions (page-data, already governed).

= 1.23.0 =
New: Numeric range constraints in get-widget-schema.
* number controls emit minimum/maximum/multipleOf from the control's min/max/step (a zero/omitted step is not emitted). slider controls expose a unit enum (size_units, else the range's unit keys) and, for a single-unit control, a size minimum/maximum from that unit's range; multi-unit sliders leave size unconstrained rather than assert a per-unit-wrong bound. Regression-tested.
* Context: a source-verified competitor pass confirmed the fork's runtime schema discovery already matches and exceeds the benchmark — get_full_controls() enables style/group controls outside the editor (Performance::set_use_style_controls()), which a bare get_controls() misses under Optimized Control Loading. This closes the one remaining schema-richness delta.

= 1.22.0 =
Removed: the vendored Freemius SDK and the upstream hosted/licensed Pro marketplace it gated.
* Deleted includes/vendors/fremius/ (~198 files), the Templates and Skills admin tabs (purely hosted/licensed content) in full, and the hosted fetchers behind the Prompts and Brand-Kits tabs, which pulled upstream's licensed content from emcp.msrbuilds.com and phoned home the site URL. Those hosted pieces were permanently dormant in this fork (no paid plans / no license path) and are not ours to serve. Severs the last runtime tie to upstream's Freemius product 30577.
* Free features retained: the Sample Prompts tab (bundled .md blueprints with one-click copy) and the Brand Kits tab (10 bundled kits, free to apply, with backup + restore via the local System_Kit_Writer + emcp_kit_backup store) stay exactly as they were — only their hosted/licensed halves (the "Sync Library" fetch + upgrade CTA) were stripped. The free brand-kit apply/backup/restore is capability-gated on manage_options, no license.
* No change to the MCP tool surface: the 19 GPL tools already registered via emcp_fork_premium_tools_enabled() with no Freemius dependency (since 1.13.0). The two hosted brand-kit tools (list/apply-brand-kits) fetched the licensed upstream library and never registered without a license — removed with it. Free bundled brand kits + local system-kit writers unchanged.
* Restored a native uninstall.php (cleanup previously ran via the Freemius after_uninstall hook): deletes the same plugin-owned options/transients/user-meta, runs the generated-executable-PHP cleanup, and best-effort clears leftover legacy fs_* options. User page content + brand-kit backups still preserved.
* Admin keeps the Tools, Connection, Prompts, Brand Kits, Widget Builder, and Changelog tabs. Distribution unchanged (GitHub releases).

= 1.21.0 =
New: Atomic schema-in-error (P1.1, second slice).
* When Elementor rejects invalid atomic widget settings (save_rejected), the error now carries the target atomic type's compact prop schema inline, so an agent can correct it in one round trip. Wired into add-atomic-widget, update-atomic-widget, and the atomic convenience tools.
* The schema is distilled from Elementor's own get_props_schema() (source-verified vs Elementor 4.1.4) via each prop's JsonSerializable form to { prop => { type, enum? } } — e.g. e-heading tag → { type: string, enum: [h1..h6] }. New Atomic_Props::schema_for() + enrich_save_rejection(). Fail-safe: only save_rejected is rewritten; never throws.

= 1.20.0 =
New: Schema-in-error widget-type suggestions (P1.1, first slice).
* When a tool gets a widget type that doesn't exist, the error now carries the nearest valid widget type names inline so an agent can self-correct in one round trip. add-widget returns invalid_widget_type with data.suggestions (ranked exact → substring → edit distance) + a schema_hint; Schema_Generator::generate() returns the same suggestions in its widget_not_found error.
* Adds Elementor_MCP_Schema_Generator::suggest_types(). Covers the wrong-widget-name mistake; attaching the full control schema to bad-settings rejections (atomic save_rejected) is a follow-up.

= 1.19.0 =
New: Post-write render check for governed page writes (P0.2 plank 3, final).
* When enabled, after a successful governed write the edited page's front end is fetched; if it comes back definitively broken (HTTP 5xx, empty body / white screen, or WordPress's "There has been a critical error on this website" fatal page), the write is reverted to its pre-write snapshot and the tool returns governance_render_failed.
* Fail-safe: a transient loopback failure (any WP_Error), a page that isn't published/publicly-viewable (drafts + elementor_library templates/popups are skipped), or a page already broken before the write (maintenance/unrelated 5xx — the page is baselined first) is inconclusive and never reverts a good write. Only a breakage the write introduced rolls back. The probe is cache-busted so a warm cache can't mask a fresh fatal.
* Opt-in (default OFF): adds a loopback request per write, so enable via the elementor_mcp_render_check option / filter. Fires elementor_mcp_governance_render_reverted on revert; opt-in cleared on uninstall.

= 1.18.0 =
New: Server-enforced approval grants for governed Elementor page writes (P0.2 plank 2).
* When SiteAgent's Ed25519 grant regime is active AND grant enforcement is opted in for this plugin, a governed write must present a valid X-Aura-Approval-Grant bound to its exact tool + params — verified BEFORE the tool runs via Aura_Worker_Grant::verify() (so a create-style tool cannot wp_insert_post an unauthorized draft). Missing grant → governance_grant_required; rejected grant → governance_grant_invalid; neither executes.
* Previews are exempt: a preview-capable tool (its schema declares an apply flag — the a11y/SEO generators) run with apply falsy writes nothing and needs no grant. Everything else (edits + create-style writes) is gated. Binds to the exposed MCP tool name (slashes → dashes), matching what the gateway signs.
* Opt-in, cannot brick governed sites: OFF by default even when a gateway key exists (the gateway must be minting grants for this plugin's tool names first). Enable via the elementor_mcp_require_grants option / filter once ready. With no key or opt-in off, writes proceed unchanged.

= 1.17.0 =
New: SiteAgent governance bridge — capture-before-write safety for page edits, active only when the SiteAgent worker (digitizer-site-worker) is installed alongside this plugin.
* Any write-capable ability that edits an existing page has the page's Elementor state (_elementor_data + _elementor_page_settings) snapshotted through SiteAgent's snapshot engine before the write, and rolled back if the write fails.
* Robust by construction, no hand-maintained tool list: the ability wrapper arms a governed run, and the actual page-data write site (save_page_data / save_page_settings) captures the snapshot lazily on the first real write. Tools that write other state (template conditions, SEO meta) or preview-only calls (apply=false) never reach the write site, so are never snapshotted — no wrong-key rollbacks, no spurious denies.
* Soft dependency: with no SiteAgent present, nothing is wrapped and behaviour is unchanged. Fail-closed: a write with no rollback point is refused; a write that errors or throws is reverted, and a failed rollback surfaces as governance_rollback_failed.
* Fires elementor_mcp_governance_write / _rolled_back / _rollback_failed seams for the gateway. Scope: page-data writes (kit/repository writes, approval grants, render checks are follow-up planks).

= 1.9.1 =
Security hardening (ported from upstream msrbuilds/elementor-mcp 4bcefc5):
* Fixed: F-004 — add-custom-css neutralises the </style> breakout (bypass-proof loop strip; valid CSS preserved).
* Fixed: F-008 — SVG sanitiser matches on*= event handlers across line breaks (multiline bypass closed).
* Fixed: F-020 — admin no longer localises the absolute server path to page JS (proxy filename only).
* Fixed: page-save data/settings persist meta even when Elementor's document save() returns null.
* Performance: list-pages WP_Query uses no_found_rows.

= 1.9.0 =
* New: AI Widget Builder (Pro) — 8 MCP tools let an agent design custom Elementor widgets from a structured spec (no hand-written PHP). The plugin compiles the spec + an HTML template into a sandboxed Widget_Base class under uploads/emcp-widgets/, escaping every value by control type. 35 control types incl. group controls (typography/border/box-shadow/background), repeaters, responsive, and conditions, plus optional per-widget CSS/JS. New widgets auto-activate under a "Custom (EMCP)" category; a runtime safety net keeps a bad widget from breaking the editor. Off-by-default; managed on the new Widget Builder tab.
* New: 10 free brand kits — the Brand Kits tab now ships 10 curated color + typography kits anyone can apply for free, with backup-before-apply and restore. The full 50-kit library stays Pro.
* New: Get Support button in the admin header on every tab, linking to the support portal (support.msrbuilds.com).
* New: Pagination on the Prompts, Templates, Brand Kits, and Changelog pages — filter-aware, and it revived the Templates category filter.
* Fixed: Prompts page froze for several seconds with 50+ prompts — off-screen 1px-wide copy textareas forced a pathological reflow; they're now display:none.
* Fixed: Atomic (V4) tool detection (#47) — atomic tools now register based on whether the atomic element types are registered (or the e_atomic_elements/atomic_widgets experiment is on), not the ELEMENTOR_VERSION constant, and not on the page-editor opt-in alone (which let writes silently no-op).

= 1.8.3 =
* New: One-click credentials on the Connection tab — pick an administrator from a dropdown (admins only, you at the top) and click Generate to automatically create a new Application Password and fill in every client config. No more creating one by hand under your profile.
* New: "Use an existing Application Password instead" fallback for anyone who prefers to paste their own.
* Security: the generator is nonce-protected, requires manage_options plus edit_user on the chosen account, only targets administrator accounts, and won't mint a password over plain HTTP (where it could not authenticate).

= 1.8.2 =
* New: The Connection tab now generates ready-to-copy npx proxy configs for Claude Code and Claude Desktop ("npx -y @msrbuilds/emcp-proxy@latest") — the recommended way to connect a remote/shared-hosting site, with no local proxy file to maintain. The bundled-proxy-file configs are still offered for local WordPress.
* Fixed: Reorganized the Connection tab proxy section into "Remote (npx)" and "Local (bundled file)" groups so remote users no longer copy a server-side filesystem path that can't work from their machine.

= 1.8.1 =
* Fixed: Clarified the Node.js proxy docs for remote/shared-hosting setups. The proxy runs as a local subprocess on the machine with your AI client, so its file path must be local — not the copy inside wp-content/plugins on the server. The Connection tab, README, and config examples now make this explicit.
* New: Zero-install npx runner for remote connections — use "command": "npx", "args": ["-y", "@msrbuilds/emcp-proxy@latest"] instead of maintaining a local copy of the proxy file that can drift from the server version.
* Changed: Documented the MCP_PROTOCOL_VERSION=2024-11-05 override in the connection docs (previously only in release notes), for clients that reject the adapter's 2025-06-18 handshake.

= 1.8.0 =
* New: SEO & Accessibility toolkit for Pro subscribers — 7 new MCP tools that audit and improve a page at the structure level (no external API, no AI cost). SEO: audit-page-seo (scored on-page report), extract-keywords-from-content, generate-meta-tags (writes to Yoast/Rank Math with apply), generate-schema-markup (JSON-LD: Article/LocalBusiness/FAQPage/Service/Product, injects with apply). Accessibility: audit-page-a11y (WCAG-oriented: contrast, alts, heading order, link text, form labels), fix-color-contrast, add-alt-text-from-context.
* New: Every page-mutating tool is dry-run by default — fixers and the generator write-back only change the site when apply:true is passed, and edits are reversible via Elementor revisions.
* New: The 7 tools are Pro-gated and disabled-by-default; enable individual tools on the EMCP Tools tab (new "SEO & Accessibility" category).
* Changed: CLAUDE.md / documentation corrected to state the real minimums (WordPress 6.9+, PHP 8.0+).

= 1.7.4 =
* New: The WordPress MCP Adapter is now bundled with the plugin — no separate adapter plugin install required. On WordPress 6.9+/7.0 (where the Abilities API is in core), Elementor is the only thing you need to install. If a standalone MCP Adapter plugin is active, the plugin automatically defers to it.
* New: "Activate Abilities API for EMCP" toggle on the Connection tab — switch the MCP server on or off for the site (on by default), with a security note that connected AI agents can create, edit, and delete Elementor content when enabled.
* New: Connection tab now shows the MCP Adapter source (bundled vs. external plugin) and the MCP Server enabled/disabled status.
* Changed: Dependency checks no longer require a separately installed MCP Adapter; the bundled copy loads automatically. Only the adapter's runtime source is bundled (it has zero runtime dependencies).

= 1.7.3 =
* New: Industry Skill Packs for the Pro Agent Skill — 10 vertical knowledge files (Dental, Med-Spa, Therapy, Fitness, Automotive, Food & Restaurant, Wedding, Real Estate, Legal, Photography). When the AI agent recognizes the site's industry it reads the matching pack before building and applies that trade's brand voice, SEO keywords, page structure, conversion patterns, compliance notes, and the exact Brand Kit + prompt + template combo.
* New: Skills admin tab now lists the bundled industry packs and explains how the skill auto-routes to the right vertical — no configuration needed.
* Changed: The bundled EMCP Agent Skill gained a vertical-routing section so it loads only the one relevant industry pack (progressive disclosure keeps token cost low). Packs ship in the premium build only.

= 1.7.2 =
* New: Brand Kits Library for Pro subscribers — one-click coordinated color palettes + typography. 16 curated kits across 4 categories (Corporate & Tech, Creative, Hospitality, Trades). Click Apply and the whole site re-skins; back up and restore any time. Auto-synced from the EMCP Tools server with the same 24h cache as Prompts and Templates.
* New: Applying a brand kit replaces the four Elementor system color + typography slots AND sets the kit's Theme Style defaults (default body/heading fonts and body/heading/link colors) so the change is visible site-wide, not just on elements that reference global tokens. Google Fonts load automatically.
* New: Backup-before-apply — current global settings are snapshotted into a private backup before each apply, with a Restore-from-backup section (selective by default, full-clobber option) on the Brand Kits page.
* New: Four Pro-gated MCP tools — list-brand-kits, apply-brand-kit, replace-system-colors, replace-system-typography — so AI clients can browse and apply kits too.
* New: Brand Kits admin tab between Templates and Skills, with category filter pills, self-contained preview cards, an apply-confirmation modal, and a "View site" toast.
* Changed: Admin stats bar shows a Brand Kits count for Pro sites with a synced library.

= 1.7.1 =
* New: Premium Templates library — apply ready-made Elementor designs to a new draft page or import them into Elementor's Saved Templates library. Auto-synced from the EMCP Tools server, category filter + thumbnails. Accepts Elementor's native template export shape.
* New: EMCP Agent Skill download for Pro subscribers — pre-written Anthropic Agent Skill with install guides for Claude Code, Claude Desktop, Cursor, Windsurf, Antigravity. New Skills admin tab.
* New: Global "Upgrade to Pro" admin banner on non-EMCP screens for non-Pro sites. Dismissible per-user.
* New: "Read the Docs" header button in the admin. Upgrade-to-Pro button hidden for active Pro sites.
* Changed: Prompts tab hides the 5 bundled samples for Pro users — premium library supersedes them.
* Changed: Stats-bar prompt count reflects the synced premium library size (e.g. 50) on Pro sites.
* Changed: All in-plugin "Upgrade to Pro" CTAs point at https://emcp.msrbuilds.com/pricing and open in a new tab.
* Changed: Reverted the Freemius pricing-screen wrapper — pricing iframe renders native again.
* Fixed: Premium prompts/templates transient caches and upgrade-banner user-meta scrubbed on uninstall.

= 1.7.0 =
* New: Premium Prompts library is now live for Pro subscribers — 50+ industry-specific landing-page prompts across 10 categories, auto-synced from the EMCP Tools server. Free users continue to see the 5 bundled sample prompts plus an upgrade CTA.
* New: Category filter pills + Sync Library button on the Prompts admin page for Pro users.
* New: "Read the Docs" link in the admin header pointing at the comprehensive docs site at https://emcp.msrbuilds.com/docs.
* New: Two-build distribution — separate free and premium zips so the Freemius account screen labels the install correctly. Paying customers see "Pro version", non-paying customers see "Free version", instead of everyone seeing "Free version" regardless of license.
* Changed: Premium prompts fetcher now sends authentication via Authorization Bearer header instead of URL query parameters. License keys belong in headers, not query strings.
* Changed: Default premium prompts endpoint moved to the dedicated subdomain `https://emcp.msrbuilds.com/api/emcp/prompts.json`.
* Changed: Admin header — removed redundant "Contact Me" button, renamed "Get Premium Prompts" → "Upgrade to Pro" pointing at the Freemius checkout, and hidden for sites with an active Pro license.
* Changed: Pricing-screen "Get in touch" link points at the new EMCP Tools about page.
* Improved: Uniform 403 error handling for the premium prompts endpoint — no info-leak about which auth condition failed.

= 1.6.1 =
* Changed: Uninstall logic moved from `uninstall.php` to the Freemius `after_uninstall` hook so Freemius's own cleanup and ours run in the right order. The `uninstall.php` file has been removed.
* Added: `elementor_mcp_low_tool_mode` and `elementor_mcp_defaults_applied` options are now cleaned up on uninstall (previously missed when those options were added in 1.6.0).
* Added: Branded chrome around the Freemius pricing screen — gradient header matching the EMCP Tools admin pages, feature highlights card above the pricing iframe, and a collapsible FAQ + contact link below it.

= 1.6.0 =
* New: Dedicated **EMCP Tools** top-level admin menu with Tools, Connection, Prompts, and Changelog submenus (previously a single tabbed screen under Settings).
* New: Atomic element tools (Elementor 4.0+) are now listed in the admin Tools screen and can be toggled individually.
* New: **Low-tools mode** — one-click toggle on the Tools screen that filters the registered tool list down to a curated essentials set, keeping the active count under 60 so MCP clients with strict tool caps (Antigravity, Gemini API, etc.) stay under their limits. Your individual toggles are preserved.
* Changed: Pro widget shortcuts are now disabled by default on fresh installs and on the first admin page load after upgrade. Re-enable any of them from the Tools screen.
* Fixed: The "disabled tools" toggles in the admin Tools screen previously had no effect on what MCP clients saw — the filter was only registered in admin context and never fired on REST API requests (#45).
* Fixed: Atomic element tools are now visible in the Tools screen and can be toggled individually (previously missing from the UI).

= 1.5.1 =
* Fixed: Container `justify_content` / `align_items` / `align_content` settings are now remapped to Elementor's prefixed flex keys (`flex_justify_content`, `flex_align_items`, `flex_align_content`) before saving — fixes containers rendering with default alignment on the front-end despite the values being persisted (#32).
* Fixed: Factory auto-center default for column containers now uses the prefixed `flex_align_items` key.
* Improved: Tool descriptions for `add-container` / `update-container` now point to the prefixed flex keys.

= 1.5.0 =
* New: 13 atomic element tools for Elementor 4.0+ — atomic flexbox, div-block, heading, paragraph, button, image, svg, youtube, video, divider, plus universal `add-atomic-widget`, `update-atomic-widget`, and `detect-elementor-version`.
* New: Typed props (`$$type`) handled automatically — AI agents pass simple flat values; styles stored in the separate `styles` map matching Elementor 4.0's data model.
* New: All atomic tools self-guard on Elementor >= 4.0 — zero changes to existing 97 legacy tools.
* Total MCP tools increased from 97 to 110.
* Addresses #28 and #29.

= 1.4.3 =
* New: 5 Pro widget convenience tools — `add-code-highlight`, `add-reviews`, `add-off-canvas`, `add-progress-tracker`, `add-search`.
* Total MCP tools increased from 92 to 97.
* Fixed: Gemini API / Antigravity compatibility — strip empty string values from enum arrays and ensure empty `properties` objects serialize as `{}` (not `[]`). Applied to all 44 ability registrations.
* Fixed: `switcher`, `popover_toggle`, `select`, and `choose` control types no longer emit empty enum values in `get-widget-schema` output.
* Fixed: `get-container-schema` input schema now uses `stdClass` for empty properties (resolves `'allOf' failed - got array, want object`).
* Fixed: Added missing `items` schema to `template_json` array property in `import-template` tool.
* Closes #21.

= 1.4.0 =
* New: 22 Pro widget convenience tools — nav menu, loop grid, loop carousel, media carousel, nested tabs, nested accordion, and more.
* New: 5 WooCommerce widget tools — products, add-to-cart, cart, checkout, menu cart (conditional on WooCommerce).
* New: 4 layout tools — update-container, update-element, batch-update, reorder-elements.
* New: 6 template/theme builder tools — create-theme-template, set-template-conditions, list-dynamic-tags, set-dynamic-tag, create-popup, set-popup-settings.
* New: 2 query tools — get-container-schema, find-element.
* New: 4 extended core widget tools — menu-anchor, shortcode, rating, text-path.
* Total MCP tools increased from 70 to 92.
* Improved: Settings validator with stricter schema enforcement.
* Improved: Element factory with enhanced container support.

= 1.3.2 =
* Renamed plugin to "MCP Tools for Elementor" to comply with WordPress.org trademark guidelines.
* Updated admin menu label to "EMCP Tools" for brevity.
* Fixed WPCS issues: prefixed all global variables in view templates, escaped integer output, added missing translators comments.
* Updated "Tested up to" to WordPress 6.9.
* Added languages/ directory for Domain Path header.

= 1.3.1 =
* New: Prompts tab in admin dashboard — browse and one-click copy 5 sample landing page prompts.
* New: Contributing Prompts guide in CONTRIBUTING.md with structure, guidelines, and submission steps.
* Improved: Admin CSS for prompt card grid with hover effects and responsive breakpoints.

= 1.3.0 =
* New: `add-custom-css` tool — add custom CSS to any element or page-level with `selector` keyword support (Pro only).
* New: `add-custom-js` tool — inject JavaScript via HTML widget with automatic `<script>` wrapping and optional DOMContentLoaded wrapper.
* New: `add-code-snippet` tool — create site-wide Custom Code snippets for head/body injection with priority and jQuery support (Pro only).
* New: `list-code-snippets` tool — list all Custom Code snippets with location, priority, and status filters (Pro only).
* Total tools increased from ~64 to ~68.

= 1.2.3 =
* Fix: Factory now strips `flex_wrap` and `_flex_size` from container settings — prevents AI agents from setting these values that cause layout overflow.
* Fix: Tool descriptions now include background color instructions (`background_background=classic`, `background_color=#hex`) so AI agents apply colors correctly.
* Improved: Stronger "NEVER set flex_wrap" guidance in build-page and add-container tool descriptions.

= 1.2.2 =
* Fix: Row container children now use `content_width: full` with percentage widths (e.g. 25% for 4 columns) matching Elementor's native column layout pattern.
* Fix: Removed all `flex_wrap` and `_flex_size` auto-overrides from factory and build-page — Elementor defaults handle layout correctly.
* Improved: Tool descriptions updated with correct multi-column layout guidance.

= 1.2.1 =
* Fix: Row containers now use `flex_wrap: wrap` instead of `nowrap` to prevent children from overflowing.
* Fix: `build-page` auto-sets percentage widths on row children (e.g. 50% for 2 columns, 33.33% for 3) instead of using `_flex_size: grow` which caused layout overflow.
* Improved: Tool descriptions updated with correct layout guidance for multi-column layouts.

= 1.2.0 =
* New: 14 free widget convenience tools — accordion, alert, counter, Google Maps, icon list, image box, image carousel, progress bar, social icons, star rating, tabs, testimonial, toggle, HTML.
* New: 10 Pro widget convenience tools — call to action, slides, testimonial carousel, price list, gallery, share buttons, table of contents, blockquote, Lottie animation, hotspot.
* Total widget tools increased from 17 to 41 (~64 MCP tools overall).

= 1.1.1 =
* Fix: Container flex layout — row children auto-grow with `_flex_size: grow` for equal distribution.
* Fix: Column containers auto-center content horizontally (`align_items: center`).
* Fix: Row containers auto-set `flex_wrap: nowrap` to prevent wrapping.
* Fix: `_flex_size` now correctly uses string value (`grow`) instead of array — prevents fatal error in Elementor CSS generator.
* Fix: `get-global-settings` input schema uses `stdClass` for empty properties to serialize as JSON `{}` instead of `[]`.
* New: Connection tab configs for Cursor, Windsurf, and Antigravity IDE clients.
* New: 3 stock image tools — `search-images`, `sideload-image`, `add-stock-image` (Openverse API).
* New: SVG icon tool — `add-svg-icon` for custom SVG icons.
* Improved: `build-page` description with detailed layout rules for row/column containers.
* Improved: Admin connection tab streamlined — removed WP-CLI local section, unified HTTP config workflow.

= 1.0.0 =
* Initial release.
* 7 read-only query/discovery tools.
* 5 page management tools (create, update settings, delete content, import, export).
* 4 layout tools (add container, move, remove, duplicate elements).
* 2 universal widget tools (add-widget, update-widget).
* 9 core widget convenience shortcuts.
* 6 Pro widget convenience shortcuts (conditional on Elementor Pro).
* 2 template tools (save as template, apply template).
* 2 global settings tools (colors, typography).
* 1 composite build-page tool.
* Admin settings page with tool toggles and connection info.
* Node.js HTTP proxy for remote connections.

== Upgrade Notice ==

= 1.32.0 =
Recommended for every site managed from Aura with SiteAgent installed: Elementor writes now honour the same operator rules SiteAgent enforces elsewhere. Sites without SiteAgent are unchanged.

= 1.31.0 =
Recommended for every site, and important if you run this plugin WITHOUT SiteAgent. 1.30.0's protection against other MCP servers on the same site relied, on standalone installs, on metadata that another plugin has to keep honouring. Write tools are now refused at the permission stage whenever the caller is not on this plugin's own MCP server, independently of any other plugin. Read tools, the documented exposure filter and dry-run previews are unaffected.

= 1.30.0 =
Security fix, recommended for every site — especially one running Elementor's Angie. This plugin's write tools were reachable from any other MCP server installed on the same site, bypassing the approval, snapshot and audit path entirely. They are now withheld from other servers, and a governed write arriving from one is refused unless it carries a valid approval grant. Read tools are unaffected. Also adds write-exposure reporting to server-info, so you can see whether a second MCP server is present on a site and whether the protection is in force there.

= 1.29.0 =
Strongly recommended if you use the atomic (Elementor 4.x) tools. Fixes nine defects, most of them silent: fonts set through any tool never applied, style changes through update-atomic-widget were dropped entirely, add-custom-css did nothing on atomic elements, and correct writes could serve stale markup from Elementor's element cache. Also publishes borders, gradients, per-side spacing, position and box-shadow on the container tools — capabilities the engine already had but never advertised — and adds server-info, a diagnostic that cannot be switched off.

= 1.28.0 =
Adds a self-updater, so future versions arrive through the normal WordPress update screens instead of a manual zip upload. Update checks are anonymous — the site URL is never sent to GitHub — and only published Releases are offered.

= 1.27.1 =
Fixes the Angie bridge in the Elementor editor, where its script never loaded at all — if you enabled the bridge and Angie showed none of its tools, this is why. Also adds a console handle for inspecting the registration state.

= 1.27.0 =
Contains a security fix: the attachment alt-text write implied by add-atomic-image/build-page is now authorized against the attachment itself (editing a page never granted edit rights over the media on it) and deferred until the page save succeeds. Also makes atomic (Elementor 4.0+) writes reliable — settings are coerced to the typed prop shapes Elementor expects, so values that previously saved and then rendered as nothing now work.

= 1.26.0 =
Adds the opt-in, off-by-default Angie bridge: six read-only inspection tools exposed to Elementor's Angie assistant. No write tools are exposed. Nothing changes unless you enable the toggle on the Connection tab.

= 1.7.1 =
Premium Templates library + EMCP Agent Skill download go live for Pro subscribers. New Skills and Templates admin tabs. Global upgrade banner on non-EMCP admin screens. All in-plugin Upgrade CTAs now route to the external pricing page on emcp.msrbuilds.com.

= 1.7.0 =
Premium Prompts go live — 50+ landing-page prompts across 10 industries, auto-synced from the EMCP Tools server for Pro subscribers. Authentication moves from query parameters to the Authorization Bearer header so license keys stop showing up in server access logs. New "Read the Docs" link in the admin header points at the new docs site.

= 1.6.1 =
Cleanup-only release: moves the uninstall handler from `uninstall.php` to the Freemius `after_uninstall` hook (required by Freemius), and adds the two options introduced in 1.6.0 to the cleanup list. No behavior changes during normal use.

= 1.6.0 =
Fixes #45 — admin tool toggles now actually filter what MCP clients see. New top-level admin menu with submenus, Low-tools mode for Antigravity/Gemini-friendly tool counts, and Pro widgets now disabled by default to stay under 100-tool client caps.

= 1.5.1 =
Fixes container `justify_content` / `align_items` / `align_content` settings not being applied on the front-end (#32). Recommended for anyone using `add-container`, `update-container`, `update-element`, `batch-update`, or `build-page` to control flex alignment.

= 1.5.0 =
Adds 13 new MCP tools for Elementor 4.0's atomic element system (110 tools total). All atomic tools self-guard on Elementor >= 4.0 with zero changes to the existing 97 legacy tools.

= 1.4.3 =
Adds 5 new Pro widget convenience tools (97 tools total) and fixes Gemini API / Antigravity compatibility — removes empty enum values and adds missing array items schema for non-Claude MCP clients.

= 1.4.0 =
Major update: 22 new tools including theme builder, dynamic tags, popup builder, WooCommerce widgets, and enhanced layout management. Total tools now 92.

= 1.3.2 =
Plugin renamed to "MCP Tools for Elementor". WPCS fixes and WordPress 6.9 compatibility.

= 1.3.1 =
New Prompts tab in admin — browse and copy sample landing page prompts directly from WordPress.

= 1.3.0 =
4 new Custom Code tools: add-custom-css, add-custom-js, add-code-snippet, list-code-snippets. Enables AI agents to inject CSS, JS, and site-wide code snippets.

= 1.2.3 =
Factory now strips flex_wrap and _flex_size from settings to prevent layout overflow. Background color guidance added to tool descriptions.

= 1.2.2 =
Fixes row layout — inner containers use content_width=full with percentage widths, no flex_wrap or _flex_size overrides.

= 1.2.1 =
Fixes row container overflow — children now use percentage widths and flex-wrap for correct multi-column layouts.

= 1.2.0 =
24 new widget convenience tools covering all major Elementor free and Pro widgets.

= 1.1.1 =
Container layout fixes, stock image tools, multi-IDE connection configs. Fixes fatal error with `_flex_size` on row containers.

= 1.0.0 =
Initial release.
