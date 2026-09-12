<?php
/**
 * Typing the code a television is showing.
 *
 * This page is used on a PHONE, standing up, looking back and forth between a
 * screen across the room and the thing in your hand. Everything here follows
 * from that: one field, a big one, no other decisions on the page, and the
 * explanation of the alphabet said out loud — because the single commonest
 * failure is somebody typing the letter O for a zero.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var string $token
 * @var list<array<string, mixed>> $tvs
 */

declare(strict_types=1);

$token ??= '';
$tvs ??= [];

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title">Sign in a television</h1>

<p class="page-subtitle" style="max-width:34rem">
  Open this site on the television. It will show a code — type it here and the television
  will come on by itself.
</p>

<form method="post" action="/tv" class="tv-code-form">
  <input type="hidden" name="_token" value="<?= e($token) ?>">

  <label for="tv-code">The code on the television</label>
  <?php
  /*
   * autocapitalize and autocorrect off, spellcheck off, and inputmode left
   * alone.
   *
   * A phone keyboard will capitalise the first character and then autocorrect
   * an eight-character nonsense word into a real one — which normalise() cannot
   * undo, because by then the characters are different. `inputmode="numeric"`
   * would be wrong too: the code is letters and digits, and a number pad gives
   * no way to type the letters.
   *
   * The pattern is advisory. The real check is PairingCode::looksReal(), and
   * the field deliberately does NOT restrict what can be typed — somebody who
   * types an O must be able to submit it, because mapping that back to a zero
   * is exactly what the server does.
   */
  ?>
  <input type="text" id="tv-code" name="code" class="tv-code"
         autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false"
         maxlength="12" required
         placeholder="XXXX-XXXX" aria-describedby="tv-code-help">

  <p class="muted small" id="tv-code-help">
    Eight characters. <strong>There are no letter Os or Is in it</strong> — a round one is a
    zero and a tall one is a one. Capital letters, and the dash does not matter.
  </p>

  <button class="btn">Sign the television in</button>
</form>

<?php if ($tvs !== []): ?>
  <h2>Televisions you have signed in</h2>

  <?php
  /*
   * Listed because a pairing is a session somebody else's device is holding,
   * and "what have I signed in" is a question people are entitled to an answer
   * to. It is a RECORD rather than a control: signing a television out means
   * signing it out, which is Account → sign out everywhere, and that already
   * exists. A per-television revoke here would be a second mechanism doing what
   * one already does, and the two would disagree about what a session is.
   */
  ?>
  <p class="muted small">A record, not a remote control. To end these, sign out everywhere
     from your <a href="/account">account</a> — that ends every session, including this one.</p>

  <ul class="tv-list">
    <?php foreach ($tvs as $tv): ?>
      <li>
        <strong><?= e((string) ($tv['device_label'] ?? 'A television')) ?></strong>
        <span class="muted small"><?= e((string) ($tv['claimed_at'] ?? '')) ?></span>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
