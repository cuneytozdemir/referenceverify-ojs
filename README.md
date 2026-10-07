# ReferenceVerify plugin for OJS

Check the references and in-text citations of a submission file with [ReferenceVerify](https://referenceverify.com/)
directly from the OJS editorial workflow: fabricated or mismatched references, retracted works, in-text citations
missing from the reference list (and the other way round), and whether cited sources support the claims made.

This branch is the **OJS 3.4 / 3.5** version. For OJS 3.3 use the `stable-3_3_0` branch.

**Requirements:** OJS 3.5.0.x or 3.4.0.x, PHP 8.0 or later with cURL, outbound HTTPS access to referenceverify.com and
kaynakcadogrula.com, and a ReferenceVerify account with a plugin key for your journal
([request one](https://referenceverify.com/ojs-plugin); editors get a free trial).

## Where it appears

- **OJS 3.5:** open a submission from the editorial dashboard; the workflow side menu has a **ReferenceVerify** item.
- **OJS 3.4:** a **ReferenceVerify** tab in the submission workflow.

It is shown to journal managers/editors, section editors (only on submissions assigned to them) and the site
administrator. Authors, reviewers and assistants do not see it.

## How it works

1. The panel lists the manuscript files of the submission (submission, review, revision, copyediting and production
   files). Each Word (.docx, .doc), PDF or RTF file has three buttons: **Reference check**, **In-text citations** and
   **Consolidated report**.
2. The chosen file is sent server-to-server (HTTPS, with your journal's plugin key) as an encrypted, single-use
   transfer of up to 50 MB, and a new browser tab opens the check on ReferenceVerify in the language of the editor's
   OJS interface.
3. **Report summaries.** Under a finished report the editor can click **Send summary to OJS**. Back in the panel,
   **Get results** adds the summary to the submission as a discussion among the assigned editors. A report the editor
   explicitly chose to share with the author also includes the submission's authors.
4. **Reviewer suggestions.** **Show reviewer suggestions** lists researchers who have published on the submission's
   topic, with ORCID, institution and conflict-of-interest flags. Nobody is assigned automatically.

## Data sent to ReferenceVerify

The plugin contacts ReferenceVerify **only when an editor clicks one of its buttons**. Opening the workflow sends
nothing; on OJS 3.5, opening the ReferenceVerify panel asks how many report summaries are waiting (submission ID only).

| Action | Data sent |
|---|---|
| Reference check / In-text citations / Consolidated report | the chosen file, its name, the submission and file IDs |
| Get results | the submission ID |
| Show reviewer suggestions | the submission's title, abstract and author names |

Transferred files are kept for at most one hour and deleted as soon as they are opened. Report summaries an editor
sends back to OJS are kept, encrypted, for at most 7 days until they are retrieved. Nothing is written to OJS
automatically. See the [privacy policy](https://referenceverify.com/legal/privacy).

## Install

1. **From the Plugin Gallery:** Settings → Website → Plugins → Plugin Gallery → **ReferenceVerify** → Install.
   Or download the release package and use **Upload A New Plugin** (site administrator). To update an installed
   version use **Upgrade** in the plugin's row; the key is kept.
2. Enable **ReferenceVerify** under Installed Plugins → Generic Plugins.
3. Click **Settings** next to the plugin and enter the **plugin key** (starts with `rvojs_`).

For testing against another server the site administrator can add to `config.inc.php`:

```ini
[referenceverify]
base_url = "https://test.example.org"
```

## Changelog

- **1.2.1** — Opening the workflow no longer contacts ReferenceVerify (on OJS 3.5 the waiting-summary count is asked
  only when the panel is opened); summaries are written to OJS only with **Get results** (the "add automatically"
  setting is gone); the server address can only be set in `config.inc.php`; the key is shown as a password field;
  files over 50 MB are refused before sending; all data flows are described in the plugin description.
- **1.2.0** — First release for OJS 3.4 and 3.5. Three report buttons per file; summaries shared with the author.

## License

Copyright (c) 2026 Cüneyt Özdemir. Distributed under the GNU General Public License v3; see [LICENSE](LICENSE).
