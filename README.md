# ReferenceVerify (Kaynakça Doğrula) — OJS 3.3 plugin

Check the references (or in-text citations) of a submission file with ReferenceVerify directly from the OJS
editorial workflow.

**How it works**

1. A **ReferenceVerify** tab appears in the workflow of every submission, for journal managers/editors,
   production editors, section editors (on submissions assigned to them) and the site administrator.
   Authors, reviewers and assistants (copyeditor, layout editor, proofreader) do not see it.
2. The tab lists the manuscript files of the submission: submission, review round, revisions, copyediting
   and production/proof files. Reviewer attachments, discussion (query) files and galley images are not listed.
   Next to each Word (.docx, .doc), PDF or RTF file the editor picks one of three reports (1.2.0):
   - **Reference check** — every reference in the reference list is verified (fabricated, mismatched, retracted);
   - **In-text citations** — in-text citations are checked against the reference list and the cited sources;
   - **Consolidated report** — both together: every reference verified (including those never cited), in-text
     citations and the reference list cross-checked in both directions (cited but missing from the list, listed
     but never cited, year mismatches) and citation claims.

   Other file types (e.g. .odt) are shown as "not supported". Files are recognised by name or by their stored type.
3. OJS sends that file server-to-server (HTTPS, with your journal's plugin key) to ReferenceVerify as an
   encrypted, single-use transfer (up to 50 MB). The transfer is kept for at most one hour and is deleted when opened.
4. A new tab opens on ReferenceVerify with the file loaded, in the same language as the editor's OJS interface
   (Turkish OJS → kaynakcadogrula.com, any other language → referenceverify.com). The check runs in the editor's
   browser; nothing is stored permanently. The editor needs a ReferenceVerify account.
5. **Report summary back to OJS (optional, 1.1.0).** When the report is finished, ReferenceVerify shows
   **Send summary to OJS**. Nothing is sent unless the editor clicks it. Only counts and the five most important
   issues are sent (no manuscript text, no references, no personal data); the summary is kept encrypted for at
   most 7 days. Back in OJS, **Get results** in the ReferenceVerify tab adds each summary to the submission as a
   discussion whose participants are the editors assigned to the submission (authors are never added), and
   ReferenceVerify then deletes it. A summary can only be fetched with the key of the journal that sent the file.
   **Share with the author (1.2.0).** Below the report the editor can also choose **Share with the author**: after
   reading a preview and ticking a confirmation, the full status of every reference (verified / to correct or check /
   not checked), suggested corrections and the in-text citation consistency are sent. **Get results** adds it as a
   discussion that also includes the submission's authors. Nothing reaches an author unless the editor sends it.
   Every report ends with "ReferenceVerify — https://referenceverify.com/".
6. **Reviewer suggestions (1.1.0).** **Show reviewer suggestions** sends the submission's title, abstract and
   author names to ReferenceVerify's reviewer search and lists candidates with ORCID, institution, works on the
   topic and conflict-of-interest flags (co-authored with an author, same institution, retracted work). Nobody is
   assigned automatically. The ReferenceVerify account linked to the plugin key must have editor tools; at most
   30 searches per hour per journal.

**Requirements:** OJS 3.3.x, PHP 7.3–8.1 (tested on 7.4, 8.0 and 8.1), PHP cURL with HTTPS, outbound HTTPS access
to referenceverify.com / kaynakcadogrula.com. Uploading a plugin package in OJS requires the **site administrator**
role.

## Install

1. OJS → Settings → Website → Plugins → **Upload A New Plugin** → choose `referenceVerify-1.2.0.tar.gz`.
   To update an installed version, use **Upgrade** in the plugin's row instead (your settings are kept).
   (Or copy the `referenceVerify` folder to `plugins/generic/` and run
   `php lib/pkp/tools/installPluginVersion.php plugins/generic/referenceVerify/version.xml`.)
2. Enable **ReferenceVerify** under Installed Plugins → Generic Plugins.
3. Click **Settings** next to the plugin and enter:
   - **Plugin key**: provided by ReferenceVerify for your journal (starts with `rvojs_`).
   - **Server address**: leave empty (only for testing).

   There is no language setting: the site language follows each editor's OJS interface language.

## Kurulum (Türkçe)

1. OJS → Ayarlar → Web Sitesi → Eklentiler → **Yeni Eklenti Yükle** → `referenceVerify-1.2.0.tar.gz`
   (paket yüklemek OJS site yöneticisi yetkisi ister). Kurulu bir sürümü güncellemek için eklentinin satırındaki
   **Yükselt**'i kullanın; ayarlarınız korunur.
2. Yüklü Eklentiler → Genel Eklentiler altında **Kaynakça Doğrula (ReferenceVerify)** eklentisini etkinleştirin.
3. **Ayarlar**'a tıklayıp Kaynakça Doğrula'nın verdiği eklenti anahtarını (rvojs_ ile başlar) girin. Sunucu adresini
   boş bırakın. Dil ayarı yoktur: site, her editörün OJS arayüz diline göre açılır.

Her gönderinin iş akışında **Kaynakça Doğrula** sekmesi çıkar. Her dosyanın yanında üç rapor düğmesi vardır:
**Kaynakça kontrolü**, **Metin içi atıf** ve **Birleşik rapor** (tüm kaynaklar doğrulanır + metin içi atıflar ile
kaynakça iki yönlü karşılaştırılır + atıf iddiaları). Rapor bitince sitede **Özeti OJS'ye gönder**'e basarsanız
(varsayılan: hiçbir şey gönderilmez), sekmedeki **Sonucu al** özeti gönderiye yalnız editörlerin gördüğü bir tartışma
olarak ekler. **Yazarla paylaş** derseniz (önizleme + onay kutusu), kaynakçanın tam durum listesi ve düzeltme önerileri
yazarların da katıldığı bir tartışma olarak eklenir; siz göndermedikçe yazara hiçbir şey gitmez.
**Hakem önerilerini göster** başlık, özet ve yazar adlarıyla ORCID, kurum ve çıkar çatışması işaretli hakem adayları
listeler (anahtarın bağlı olduğu hesapta editör araçları açık olmalı).

## Changelog

- **1.2.0** — Three reports per file: Reference check, In-text citations, Consolidated report (replaces the
  journal-wide "Tool to open" setting). Reports the editor chooses to **share with the author** are added as a
  discussion that also includes the submission's authors (editor summaries stay editor-only). Summaries now show
  in-text citation ↔ reference list counts. Upgrade from 1.1.x keeps the key and other settings.
- **1.1.1** — The ReferenceVerify tab shows "N summaries waiting" (tab title, a notice with **Get results**, and
  a label next to the file). New optional setting: add waiting summaries to the discussions automatically when the
  tab opens (off by default). Upgrade from 1.1.0 keeps all settings.
- **1.1.0** — "Get results": report summaries the editor chose to send are added as editor-only discussions.
  "Reviewer suggestions": candidates with ORCID, institution and conflict flags. Settings are kept on upgrade.

- **1.0.2** — Only manuscript stages are listed (no reviewer attachments, discussion files, galley images);
  unsupported types shown as such; files recognised by stored type as well as name; large files streamed
  (no 50 MB copy in PHP memory) with a longer transfer time limit. Tested on PHP 7.4, 8.0, 8.1.
- **1.0.1** — Site language follows the OJS interface language; language setting removed.
- **1.0.0** — First release (pilot).

License: GNU GPL v3.
