---
layout: home

hero:
  name: Parakit
  text: Payments for Kurdistan & Iraq
  tagline: پارەکیت — a Laravel-native payment kit. FIB, ZainCash, Nass Pay, Nass Wallet & FastPay, with idempotent webhooks, retries, and localised UIs out of the box.
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

| Gateway          | Driver key   | Flow                                | Refunds |
| ---------------- | ------------ | ----------------------------------- | ------- |
| First Iraqi Bank | `fib`        | QR code + readable code + deep link | Yes     |
| ZainCash         | `zaincash`   | Hosted redirect                     | Yes     |
| Nass Pay         | `nass`       | Hosted redirect                     | No      |
| Nass Wallet      | `nasswallet` | Hosted redirect                     | No      |
| FastPay          | `fastpay`    | Hosted redirect                     | Yes     |

Each gateway has its own page with credentials, payment flow, and webhook setup —
start with [FIB](/gateways/fib). Need one that isn't here? Implement the
`PaymentGateway` contract — see [Writing a custom gateway](/guides/custom-gateway).
