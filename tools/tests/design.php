<?php
// Design module checks: the DESIGN.md parser, token merging, contrast math and
// optima_core_owns(). Runs without WordPress:
//   php tools/tests/design.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
function apply_filters( $tag, $value ) { return $value; } // Enough of WordPress for the pure parts.
require __DIR__ . '/../../optima-core/includes/design.php';

$md = <<<'MD'
---
design: 1
name: Optima Digital
mode: dark                  # light or dark
colors:
  bg: "#12163A"             # page canvas
  surface: "#1B2150"
  text: "#F6F8FB"
  text_2: "#B7C0D6"
  line: "rgba(255,255,255,.10)"
  accent: "#1272C4"         # the one spark
  accent_fill: "#0F63AB"
  on_accent: "#FFFFFF"
fonts:
  heading: { family: "IBM Plex Sans", weight: 600, file: "assets/fonts/hk-600.woff2" }
  label:   { family: "IBM Plex Mono", weight: 500, case: upper, tracking: "1.4px" }
radius: { pill: "980px", card: "18px" }
logo: assets/img/logo.svg   # a comment with a # inside "quotes" stays out
---

# Optima Digital: design rules

Some prose with a colon: it is not a token.

## Feature: call buttons
- bar: "#0F63AB"
- button style: pill
- which buttons: call, text

## Feature: Reviews (optima-reviews asks: star color)
star color: "#FDC134"
surface: "#232B38"

## Something else
accent: "#FF0000"
MD;

$p = Optima_Core_Design::parse( $md );
$t = $p['tokens'];
ok( 'Optima Digital' === ( $t['name'] ?? '' ), 'name read' );
ok( 'dark' === ( $t['mode'] ?? '' ), 'comment after a value is dropped' );
ok( '#12163A' === ( $t['colors']['bg'] ?? '' ), 'nested color read, quotes removed, # kept' );
ok( 'rgba(255,255,255,.10)' === ( $t['colors']['line'] ?? '' ), 'rgba value kept whole' );
ok( 600 === ( $t['fonts']['heading']['weight'] ?? 0 ), 'inline map: number' );
ok( 'IBM Plex Sans' === ( $t['fonts']['heading']['family'] ?? '' ), 'inline map: quoted string with a space' );
ok( 'upper' === ( $t['fonts']['label']['case'] ?? '' ) && '1.4px' === ( $t['fonts']['label']['tracking'] ?? '' ), 'inline map: several keys' );
ok( '18px' === ( $t['radius']['card'] ?? '' ), 'inline radius map' );
ok( 'assets/img/logo.svg' === ( $t['logo'] ?? '' ), 'unquoted path with a trailing comment' );

$f = $p['features'];
ok( isset( $f['call_buttons'] ), 'feature heading becomes a key' );
ok( '#0F63AB' === ( $f['call_buttons']['bar'] ?? '' ), 'bullet line read' );
ok( 'pill' === ( $f['call_buttons']['button_style'] ?? '' ), 'multi-word key becomes snake_case' );
ok( 'call, text' === ( $f['call_buttons']['which_buttons'] ?? '' ), 'value with a comma kept' );
ok( '#FDC134' === ( $f['reviews']['star_color'] ?? '' ), 'heading with a parenthesised note; plain key: value lines' );
ok( ! isset( $f['something_else'] ), 'a heading that is not Feature: ends the section' );
ok( ! isset( $f['reviews']['accent'] ), 'lines after the next heading are not read into the feature' );

$merged = Optima_Core_Design::merge( Optima_Core_Design::defaults(), $t );
ok( '#12163A' === $merged['colors']['bg'] && '#EEF2F7' === $merged['colors']['surface_2'], 'merge: file wins, defaults fill the gaps' );
ok( 400 === $merged['fonts']['body']['weight'] && 'IBM Plex Sans' === $merged['fonts']['body']['family'], 'merge: untouched nested defaults stay' );
$empty = Optima_Core_Design::merge( array( 'a' => 'keep' ), array( 'a' => '' ) );
ok( 'keep' === $empty['a'], 'merge: an empty string never erases a value' );

ok( 21.0 === Optima_Core_Design::contrast( '#000000', '#FFFFFF' ), 'contrast black on white is 21' );
ok( 1.0 === Optima_Core_Design::contrast( '#777', '#777777' ), 'contrast: short hex, same color is 1' );
ok( abs( Optima_Core_Design::contrast( '#FFFFFF', '#0F63AB' ) - 4.69 ) < 0.05, 'white on the accent fill is about 4.69 (passes AA)' );
ok( abs( Optima_Core_Design::contrast( '#FFFFFF', '#1272C4' ) - 3.45 ) < 0.05, 'white on bright azure is about 3.45 (fails AA)' );
ok( 0.0 === Optima_Core_Design::contrast( '#FFF', 'rgba(0,0,0,.5)' ), 'translucent colors are not judged' );
ok( array( 18, 114, 196 ) === Optima_Core_Design::rgb( 'rgb(18, 114, 196)' ), 'rgb() parsed' );

$issues = Optima_Core_Design::issues( $merged );
$by = array();
foreach ( $issues as $i ) { $by[ substr( $i['pair'], 0, strpos( $i['pair'], ' (' ) ) ] = $i; }
ok( ! empty( $by['Button text on the accent fill']['ok'] ), 'issues: the Optima fill passes' );
ok( ! empty( $by['Text on the page']['ok'] ), 'issues: light text on the dark page passes' );
$bad = $merged; $bad['colors']['accent_fill'] = '#1272C4';
$bad_issues = Optima_Core_Design::issues( $bad );
ok( in_array( false, array_column( $bad_issues, 'ok' ), true ), 'issues: a bright fill under white text fails' );

ok( false === Optima_Core_Design::owns( 'hours' ), 'owns: no plugin loaded means false' );
ok( false === Optima_Core_Design::owns( 'nonsense' ), 'owns: unknown jobs are false' );
eval( 'class Optima_Hours_Front {}' ); // Declared now, not hoisted, so the check above was honest.
ok( true === Optima_Core_Design::owns( 'Hours' ), 'owns: the plugin class makes it true (any case)' );

$css = Optima_Core_Design::css();
ok( 0 === strpos( $css, ':root{' ) && false !== strpos( $css, '--optima-accent-fill:#0F63AB;' ), 'css: a :root block with the fill' );
ok( false !== strpos( $css, '--optima-radius-pill:980px;' ) && false !== strpos( $css, '--optima-font-heading:"IBM Plex Sans", sans-serif;' ), 'css: radius and font variables' );

echo $fail ? "\n$fail FAILED\n" : "\nAll design checks passed\n";
exit( $fail ? 1 : 0 );
