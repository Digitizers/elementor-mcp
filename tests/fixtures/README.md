# Test fixtures

## `elementor-controls.json`

The real control names of every Elementor widget a convenience tool creates
(`add-heading`, `add-image`, …), and the on-value of each switcher control.
`ConvenienceToolControlsTest` holds the tools' advertised parameters, offered
values and defaults against it: a parameter that is not a control of its widget
is saved and ignored, and nothing else in the suite can see that.

It is read from a live site, because a widget's full control stack exists only
at runtime. `dump-controls.php` is the read-only script (it enables Elementor's
style controls the way `Elementor_MCP_Schema_Generator::get_full_controls()`
does — without that, WP-CLI sees no style controls at all):

```bash
ssh <site> 'cd <wp root> && wp eval-file -' < tests/fixtures/dump-controls.php > controls.json
```

Then reduce it to names (the `common` list is the intersection across widgets;
each widget keeps only what is its own). The header of the JSON says which
Elementor and Elementor Pro versions it was read from, and when.

The five WooCommerce widgets are not in it: the site it was read from has no
WooCommerce. Their tools are skipped by the test, not passed.
