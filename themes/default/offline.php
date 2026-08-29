<?php
/**
 * Shown when the network is gone.
 *
 * DELIBERATELY EMPTY OF CONTENT. This page is precached by the service worker,
 * which means a copy is stored on the device and shown to whoever opens the app
 * next — possibly weeks later, possibly a different person on a shared machine.
 * Anything personal, anything access-gated, and anything that goes stale has no
 * business here.
 *
 * It also renders without the site header, on purpose: the header reads the
 * signed-in user and the navigation, and a cached copy of either would be wrong
 * for somebody by the time it is shown.
 *
 * @var string $siteName
 */

declare(strict_types=1);

$siteName ??= 'Video Portal';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>You are offline</title>
<meta name="robots" content="noindex, nofollow">
<style>
  /* Inline, because a stylesheet request is exactly what has just failed. */
  body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
    background: #0f172a;
    color: #e2e8f0;
    text-align: center;
    padding: 2rem;
  }
  .panel { max-width: 26rem; }
  h1 { font-size: 1.25rem; font-weight: 600; margin: 0 0 .75rem; }
  p { margin: 0 0 1rem; color: #94a3b8; line-height: 1.6; }
  button {
    font: inherit;
    padding: .55rem 1.1rem;
    border-radius: .5rem;
    border: 1px solid #334155;
    background: transparent;
    color: inherit;
    cursor: pointer;
  }
</style>
</head>
<body>
  <div class="panel">
    <h1>You are offline</h1>
    <p>This page needs a connection and there is not one right now.</p>
    <p>Nothing has been lost — try again once you are back on a network.</p>
    <button type="button" onclick="location.reload()">Try again</button>
  </div>
</body>
</html>
