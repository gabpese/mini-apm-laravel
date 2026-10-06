# Security policy

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Report it privately through GitHub: open the **Security** tab of this repository and choose **Report a vulnerability**. Only the maintainer can see the report.

A good report says what is affected, how to reproduce it and what an attacker could do. A short proof of concept helps.

This is a one-person, open source project, so replies are best effort. I will acknowledge a report as soon as I can, say whether I could reproduce it, and fix confirmed problems in the `main` branch. Please give me reasonable time to do so before you share the details publicly.

## What is covered

Only the `main` branch is maintained. There are no released versions.

The code is a portfolio project built around an invented application. It is not meant to be exposed to the internet with real data as it is, so reports about hardening that a production deployment would need (for example, rate limits at a proxy, or HTTPS) are welcome but have low priority.

Problems that matter most are the ones in the code itself: a way to read or change another user's projects, to send events to a project without its API key, to recover the text of a stored API key, or to run code on the server.

## Dependencies

Dependabot watches the dependencies and opens pull requests for updates, and for known vulnerabilities in them. Secret scanning and push protection are on for this repository.
