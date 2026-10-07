# ReferenceVerify (Kaynakça Doğrula) — OJS 3.4 / 3.5 plugin

Check the references and in-text citations of a submission file with ReferenceVerify directly from the OJS
editorial workflow.

**Compatibility:** OJS 3.5.0.x (tested on 3.5.0.4 and 3.5.0.5) and OJS 3.4.0.x. PHP 8.0 or later (tested on PHP 8.3),
PHP cURL with HTTPS, outbound HTTPS access to referenceverify.com / kaynakcadogrula.com. For OJS 3.3 use the
separate `referenceVerify-1.x.tar.gz` package. Uploading a plugin package requires the **site administrator** role.

## Where it appears

- **OJS 3.5:** open a submission from the editorial dashboard; the workflow side menu has a **ReferenceVerify** item
  (below Publication), with the number of waiting report summaries in brackets. OJS 3.5 replaced the workflow page
  with a new interface that no longer allows plugin tabs; the side-menu item uses the extension point PKP provides
  for this.
- **OJS 3.4:** a **ReferenceVerify** tab in the submission workflow, as in the OJS 3.3 plugin.

It is shown to journal managers/editors, section editors (only on submissions assigned to them) and the site
administrator. Authors, reviewers and assistants do not see it.

## How it works

1. The panel lists the manuscript files of the submission: submission, review round, revisions, copyediting and
   production/proof files. Reviewer attachments, discussion files and galley images are not listed. Each Word
   (.docx, .doc), PDF or RTF file has three buttons: **Reference check**, **In-text citations** and
   **Consolidated report**; other types (e.g. .odt) are shown as "not supported".
2. OJS sends that file server-to-server (HTTPS, with your journal's plugin key) to ReferenceVerify as an encrypted,
   single-use transfer (up to 50 MB), kept for at most one hour and deleted when opened.
3. A new browser tab opens on ReferenceVerify with the file loaded, in the language of the editor's OJS interface
   (Turkish → kaynakcadogrula.com, any other language → referenceverify.com). The editor needs a ReferenceVerify
   account. Nothing is stored permanently.
4. **Report summaries.** If the editor clicks **Send summary to OJS** under a finished report, **Get results** in the
   panel adds it to the submission as a discussion among the assigned editors (a report the editor chose to share
   with the author also includes the submission's authors). Participants get an OJS notification. With the setting
   "Add waiting summaries automatically" they are added as soon as the panel is opened.
5. **Reviewer suggestions.** **Show reviewer suggestions** lists researchers who published on the submission's topic,
   with ORCID, institution and conflict-of-interest flags (co-author, same institution, retracted work). Nobody is
   assigned automatically. The ReferenceVerify account linked to the key must have editor tools.

## Install

1. OJS → Settings → Website → Plugins → **Upload A New Plugin** → choose `referenceVerify-ojs35-1.2.0.tar.gz`
   (site administrator). To update an installed version use **Upgrade** in the plugin's row (settings are kept).
2. Enable **ReferenceVerify** under Installed Plugins → Generic Plugins.
3. Click **Settings** next to the plugin and enter the **plugin key** (starts with `rvojs_`). Leave "Server address"
   empty (only for testing). There is no language setting.

## Kurulum (Türkçe)

1. OJS → Ayarlar → Web Sitesi → Eklentiler → **Yeni Eklenti Yükle** → `referenceVerify-ojs35-1.2.0.tar.gz`
   (OJS site yöneticisi yetkisi gerekir). Kurulu sürümü güncellemek için satırdaki **Yükselt**'i kullanın.
2. Genel Eklentiler altında **Kaynakça Doğrula (ReferenceVerify)** eklentisini etkinleştirin.
3. **Ayarlar**'a tıklayıp Kaynakça Doğrula'nın verdiği eklenti anahtarını (rvojs_ ile başlar) girin; sunucu adresini
   boş bırakın.

OJS 3.5'te gönderiyi editör panosundan açın: iş akışının sol menüsünde **Kaynakça Doğrula** öğesi vardır (bekleyen
özet varsa yanında sayısı yazar). OJS 3.4'te iş akışında **Kaynakça Doğrula** sekmesi çıkar. Her Word/PDF dosyasının
yanında **Kaynakça kontrolü**, **Metin içi atıf** ve **Birleşik rapor** düğmeleri vardır; kontrol yeni sekmede açılır.
**Sonucu al** gönderilen rapor özetlerini gönderiye editörlerin gördüğü bir tartışma olarak ekler (yazarla paylaşılan
rapor yazarı da kapsar). **Hakem önerilerini göster** ORCID, kurum ve çıkar çatışması işaretli adayları listeler.

## Changelog

- **1.2.0** — First release for OJS 3.4 and 3.5 (same features and settings as the OJS 3.3 plugin 1.2.0). Three
  report buttons per file; summaries shared with the author include the authors; the "tool" setting is gone.

License: GNU GPL v3.
