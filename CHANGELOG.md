# Changelog

All notable changes to PAM WhatsApp Web will be documented here.

## Unreleased

- Reject phone numbers with a leading zero with a clear error instead of an
  opaque `InvalidWidError` from WhatsApp Web, since a country code never starts
  with zero.
- Wait for WhatsApp Web core modules before installing the bridge, and report
  the installation error in the exception.

## 1.2.0 - 2026-08-26

- Add a complete README cookbook with a working PHP example for every row in
  the upstream Supported features table.
- Add `supported-features.json` and a release gate that maps all 32 upstream
  rows to real PHP APIs and documentation examples.
- Add the `sendVideoToNumber()` convenience helper, including GIF-style video
  sending through typed options.
- Preserve truthful upstream statuses for deprecated Buttons/Lists and planned
  Communities support.

## 1.1.0 - 2026-08-26

- Add normalized unsaved-contact delivery through `sendMessageToNumber()`.
- Add one-call image, audio, document, and sticker helpers.
- Add opt-in bounded retries with contextual `OperationException` failures.
- Add simplified sent, delivered, read, and failed acknowledgement listeners.
- Add opt-in deferred browser reconnection, explicit reconnect, structured
  logging, and typed session diagnostics.
- Preserve all existing APIs and default runtime behavior.
- Document the exact upstream supported-feature boundary, including deprecated
  buttons/lists and the upstream communities limitation.

## 1.0.2 - 2026-08-25

- Add `TerminalQrCode`, a compact half-block renderer that cuts terminal QR
  output to roughly half the previous width and height.
- Simplify the quick-start example to one QR rendering call with no visible
  ANSI escape sequences.

## 1.0.1 - 2026-08-25

- Translate the complete public documentation and terminal listener example to
  English.
- Simplify terminal output by using ordinary line breaks instead of screen
  clearing control sequences.

## 1.0.0 - 2026-08-25

- Add a pure PHP 8.5+ WhatsApp Web client running on PAM without Node.js,
  Puppeteer, or Playwright.
- Control Chrome and Chromium directly through `pushinbr/pam-browser` and CDP.
- Pin compatibility to `whatsapp-web.js` 1.34.7 main commit
  `942d236a11ad68807308b058303ba5256915979c`.
- Map all 81 public symbols and 670 public members in a machine-readable parity
  matrix.
- Add typed authentication, messages, media, chats, contacts, groups, channels,
  calls, polls, commerce payloads, scheduled events, and all public events.
- Add lazy chunked `downloadMediaStream()` parity and media metadata contracts.
- Support commands issued directly from event callbacks by safely routing
  nested CDP responses.
- Add deterministic and authenticated certification runners whose union covers
  all 751 matrix entries.
- Add PHPStan level 9, PHPUnit, bridge, legacy, parity, and certification gates.
- Add a scan-ready terminal QR example with persistent authentication and live
  inbound message output.
