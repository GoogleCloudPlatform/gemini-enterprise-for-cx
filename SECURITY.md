# Security Policy

## Reporting a Vulnerability

Please **do not** report security vulnerabilities through public GitHub issues,
pull requests, or discussions.

Report them through Google's Vulnerability Reward Program intake instead:

**https://g.co/vulnz**

Google runs coordinated vulnerability disclosure. Reports sent there reach the
security team directly, are triaged on a defined timeline, and may be eligible
for a reward. We will keep you informed of progress towards a fix and coordinate
on disclosure timing.

## What to Include

A report is far easier to act on when it says:

* The plugin version (`Stable tag` in `readme.txt`, or the `Version` header in
  `gecx-agent.php`).
* The WordPress and WooCommerce versions the store is running.
* Which component is affected — the REST routes under `gecx/v1`, cart-token
  authentication, the admin onboarding flow, the storefront widget, or
  uninstall.
* Steps to reproduce, and what an attacker gains.
* Whether the issue requires an authenticated user, and at what capability.

## Scope

This repository contains the WordPress plugin only. The Gemini Enterprise for CX
service it talks to (`gecx.cloud.google.com`) and the chat widget bundle served
from `gstatic.com` are separate systems, but the intake at https://g.co/vulnz
covers all of them, so report through it either way and say which component you
believe is affected.

## Supported Versions

Only the most recent release receives security fixes. The current release is
whichever version `Stable tag` in `readme.txt` names.
