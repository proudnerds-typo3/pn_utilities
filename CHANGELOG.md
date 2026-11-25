# Changelog

All notable changes to `pn_utilities` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versions follow the TYPO3 core version the extension targets.

## [Unreleased]

### Security

- **Administration > Backend users**: the email address of an existing user
  can no longer be changed. A user manager could set it to their own address,
  send the password reset link there and log in as that user.
- **Administration > Backend users**: users with permissions on their own
  record (DB or file mounts, modules, languages or TSconfig) are no longer
  listed, so taking over or editing them cannot extend a manager's rights.

### Changed

- **Content > Tools** shows 100 pages per page, and only builds frontend links
  for the pages it shows.

## [14.3.0] – 2026-11-25

First release as a separate package; earlier history comes from the
typo3-seed installation.

### Added

- **Content > Tools**: pages that have not been changed for a given number of
  days, including translations and hidden pages
- **Administration > Backend users**: backend user management for non-admins,
  limited to groups marked as assignable
- Extension configuration `stalePages` and `userManagement` to switch each
  module on or off
- Command `pn:powermail:convert`
- Editor manual as a dashboard widget

[Unreleased]: https://github.com/proudnerds-typo3/pn_utilities/compare/14.3.0...HEAD
[14.3.0]: https://github.com/proudnerds-typo3/pn_utilities/releases/tag/14.3.0
