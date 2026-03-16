# FTP install (no Composer on the server)

This document describes a **minimal** BlackCat deployment on hosts where you can only upload files (FTP/SFTP) and you **cannot** run Composer on the server.

Scope:
- runtime: `blackcat-core` + `blackcat-config` (TrustKernel boot + guards)
- chain authority: `blackcat-kernel-contracts` (per-install `InstanceController`)
- build step happens on your workstation or CI (Composer available there)

If you also cannot run **any** CLI on the server, this still works: upload everything prebuilt and use web-only verification.

## 0) Requirements (strict production)

To run a strict production policy safely, you need:
- HTTPS (end-to-end; do not terminate to plain HTTP between proxy and app)
- a single front controller (route everything to `public/index.php`)
- ability to keep `config.runtime.json` **outside** web docroot (recommended: docroot is `public/`)
- at least 2 RPC endpoints + quorum >= 2 (strict policy requirement)

If your hosting cannot meet these, you can still use BlackCat, but it should be treated as a **non-production** posture.

## 1) Recommended on-disk layout

Put this directory on the server (upload via FTP):

```
app/                          # integrity root (recommended)
  public/                     # web docroot
    index.php                 # front controller (boots HttpKernel early)
    .htaccess                 # Apache deny rules (recommended)
  vendor/                     # prebuilt dependencies (Composer output)
  composer.lock               # recommended when using strict policy v4/v5
  config.runtime.json         # runtime config (strict perms; NOT in docroot)
  integrity.manifest.json     # integrity manifest (public-readable allowed)
  .blackcat/
    tx-outbox/                # optional (buffer tx intents / incidents)
```

Notes:
- `config.runtime.json` **must not** be inside the web docroot.
- `integrity.manifest.json` is not secret, but it must not be writable by group/world.

## 2) Build the upload bundle (workstation / CI)

On your workstation (or CI), prepare the directory you will upload:

1) Copy kernel HTTP templates:
   - `blackcat-core/templates/http/index.php` → `app/public/index.php`
   - `blackcat-core/templates/http/.htaccess` → `app/public/.htaccess` (Apache)

2) Install dependencies locally (server does not need Composer):

```bash
cd app
composer install --no-dev --optimize-autoloader --classmap-authoritative
```

Your `composer.json` should include at least:
- `blackcatacademy/blackcat-core`
- `blackcatacademy/blackcat-config`
- `blackcatacademy/blackcat-cli` (recommended for generating config/attestations locally)

## 3) Generate the integrity manifest + compute root

From your workstation, build the manifest over the **exact** directory you will upload:

```bash
php vendor/blackcatacademy/blackcat-core/scripts/trust-integrity-manifest-build.php \
  --root="$(pwd)" \
  --out="integrity.manifest.json" \
  --pretty
```

Record the reported:
- `root` (bytes32) — commit it on-chain as `activeRoot`
- `uri_hash` (bytes32) — optional, but recommended

## 4) Create runtime config (portable/shared-hosting friendly)

Generate a portable Edgen template:

```bash
vendor/bin/blackcat config runtime template trust-edgen-portable --json > config.runtime.json
```

Then edit `config.runtime.json` and fill at least:
- `trust.web3.contracts.instance_controller` (your per-install clone address)
- `trust.web3.rpc_endpoints` (>= 2 HTTPS endpoints)
- `trust.web3.rpc_quorum` (>= 2)

Portable template note:
- it uses **relative paths** resolved relative to `config.runtime.json`.
- keep `trust.integrity.root_dir="."` and place `config.runtime.json` in your **integrity root** (`app/`).

## 5) Commit to the chain (per-install)

On your per-install `InstanceController`:
- set `activeRoot` to the computed root
- set `activeUriHash` (optional)
- set `activePolicyHash` to a **strict** policy (recommended baseline: `TrustPolicyV3 strict`)

If you use policy v3+, compute runtime-config attestation and set+lock it:

```bash
vendor/bin/blackcat config runtime attestation runtime-config --path=config.runtime.json
```

Follow the `blackcat-kernel-contracts` docs for how to set `attestations[key]=value` and lock the key.

## 6) Upload + switch to immutable posture

1) Upload `app/` to the server (FTP/SFTP).
2) Configure the domain docroot to `app/public/` (recommended).
3) Immediately disable/remove FTP access after upload.

## 7) Verify (no-CLI friendly)

- Open your site and check the TrustKernel status endpoint you expose (recommended).
- If you have PHP CLI on the server, run:

```bash
php vendor/blackcatacademy/blackcat-core/scripts/trust-kernel-install-verify.php --pretty
```

Related docs:
- `blackcat-core/docs/MINIMAL_SECURITY_STACK.md`
- `blackcat-core/docs/FRONT_CONTROLLER.md`
- `blackcat-core/docs/DEPLOYMENT_HARDENING.md`
- `blackcat-config/docs/NO_CLI_SETUP.md`
- `blackcat-config/docs/TRUST_KERNEL_EDGEN.md`

