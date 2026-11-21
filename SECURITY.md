# Security policy

This is a community project, **not affiliated with Selcom Paytech Ltd.**

## Reporting a problem in this package

Please report it privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability** (private vulnerability reporting). Do not open a public issue for a security problem.

Include what you found, how to reproduce it, and the version. Use made-up data only: never send real credentials, PINs, card tokens or customer data.

We aim to acknowledge a report within a few days and to fix confirmed problems before disclosing them.

## Supported versions

Until a stable release exists, only the latest commit on `main` is supported.

## Out of scope

Problems with Selcom's gateway, its API, your credentials or your contract are for Selcom: <https://developers.selcommobile.com/>.

## Handling credentials safely

- Never commit the API secret, the float PIN or card tokens. Read them from the environment or a secret store.
- Send anything you store or log through the `Redactor` binding. The package's own logging and stored webhooks already do.
- Treat webhooks as hints and confirm with `orderStatus` before fulfilling.
