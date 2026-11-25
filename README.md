# TYPO3 extension `pn_utilities`

Backend tools for TYPO3 14.3:

- **Content > Tools** lists the pages below the selected page whose properties
  and content elements have not been changed for a given number of days
  (default 182). Admins only.
- **Administration > Backend users** lets non-admins create, edit, disable and
  delete backend users, limited to the groups an admin has marked as
  assignable. New users set their own password through a link sent by mail.
- `pn:powermail:convert` converts legacy Powermail forms, read straight from
  the database tables, into ext:form `*.form.yaml` definitions.

An editor manual in Dutch is registered as a dashboard widget through
[`pn_backend_manual`](https://github.com/proudnerds-typo3/pn_backend_manual);
it is also the most complete description of both modules:
[`Resources/Private/Manual/UtilitiesManual.md`](Resources/Private/Manual/UtilitiesManual.md).

## Installation

```bash
composer require proudnerds/pn-utilities
```

## Configuration

Each module can be switched on or off in **Admin > Settings > Extension
Configuration > pn_utilities**:

| Setting | Default | Effect |
| --- | --- | --- |
| `stalePages` | on | Registers **Content > Tools** |
| `userManagement` | off | Registers **Administration > Backend users** and adds the field *Assignable in user management* to backend groups |

After switching `userManagement` on, update the database schema
(**Analyze Database Structure** in the Install Tool, or
`typo3 database:updateschema`).

### User management

1. Mark the groups that user managers may hand out as *Assignable in user
   management* (tab **General** of the backend group). A user who gets such a
   group gets all permissions of that group and its subgroups.
2. Give the group of the user managers access to the module **Backend users**.
3. Make sure the installation can send mail.

User managers only see users whose groups are *all* assignable. Admins, users
without a group and their own account are never listed. Every change is
written to the TYPO3 log.

### Converting Powermail forms

```bash
typo3 pn:powermail:convert                 # list the forms
typo3 pn:powermail:convert 2               # convert form uid 2
typo3 pn:powermail:convert --all           # convert every form
typo3 pn:powermail:convert 2 --path=EXT:my_sitepackage/Resources/Private/Forms/
```

Powermail itself does not need to be installed. The default target path is
`EXT:base_sitepackage/Resources/Private/Forms/`; installations without that
package must pass `--path`, and the path must be listed in the form
`persistenceManager.allowedExtensionPaths`.

## Development

```bash
composer install
.Build/bin/phpunit -c phpunit.xml             # unit tests
.Build/bin/phpunit -c phpunit.functional.xml  # functional tests, SQLite
```

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
