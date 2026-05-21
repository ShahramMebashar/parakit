---
layout: home

hero:
  name: Parakit
  text: Payments for Kurdistan & Iraq
  tagline: پارەکیت — a Laravel-native payment kit. FIB, ZainCash, Nass Pay, Nass Wallet, FastPay & QiCard, with idempotent webhooks, retries, and localised UIs out of the box.
  actions:
    - theme: brand
      text: Get started
      link: /introduction
    - theme: alt
      text: Installation
      link: /installation
    - theme: alt
      text: View on GitHub
      link: https://github.com/ShahramMebashar/parakit

features:
  - title: Local gateways, one API
    details: FIB QR + deep link, ZainCash hosted redirect, and more — behind a single fluent charge builder.
  - title: Reliable by default
    details: Idempotent webhooks, retries with a circuit breaker, and a sweeper that recovers lost webhooks.
  - title: Localised UIs
    details: Three payment UIs shipped in English, Arabic, and Sorani Kurdish (en / ar / ckb).
  - title: First charge in 15 minutes
    details: composer require, parakit:install, then parakit:doctor and a sandbox test-charge.
---

## Supported gateways

| Gateway          | Driver key   | Flow                                | Refunds | Cancel | Signed webhooks |
| ---------------- | ------------ | ----------------------------------- | ------- | ------ | --------------- |
| First Iraqi Bank | `fib`        | QR code + readable code + deep link | Yes     | Yes    | Re-fetch        |
| ZainCash         | `zaincash`   | Hosted redirect                     | Yes     | No     | JWT (HS256)     |
| Nass Pay         | `nass`       | Hosted redirect                     | No      | No     | Re-fetch        |
| Nass Wallet      | `nasswallet` | Hosted redirect                     | No      | No     | Re-fetch        |
| FastPay          | `fastpay`    | Hosted redirect                     | Yes     | No     | Re-fetch        |
| QiCard           | `qicard`     | Hosted card form (3DS)              | Yes     | Yes    | RSA-2048 SHA256 |

Each gateway has its own page with credentials, payment flow, and webhook setup —
start with [FIB](/gateways/fib). Need one that isn't here? Implement the
`PaymentGateway` contract — see [Writing a custom gateway](/guides/custom-gateway).
