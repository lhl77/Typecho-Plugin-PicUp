# PicUp for Typecho

[PicUp](https://github.com/lhl77/Typecho-Plugin-PicUp) is an open-source image and file upload plugin for [Typecho](https://typecho.org/). It includes built-in S.EE support, so every image or attachment you upload in Typecho is stored on S.EE automatically — no manual steps needed.

- [Download PicUp for Typecho](https://github.com/lhl77/Typecho-Plugin-PicUp/releases)
- [Source Code](https://github.com/lhl77/Typecho-Plugin-PicUp)

---

## Setup

### 1. Get your API Token

1. Log in to your S.EE account
2. Go to **[Tools > API Token](https://s.ee/user/developers/)**
3. Generate a new API Token

> Don't share your API Token publicly or commit it to version control.

### 2. Install PicUp

**Option A — AB-Store (recommended)**

Install the [AdminBeautify](https://github.com/lhl77/Typecho-Plugin-AdminBeautify) plugin, open **AB-Store** in the Typecho admin panel, search for **PicUp**, and install with one click.

**Option B — Manual**

1. Download the latest ZIP from [GitHub Releases](https://github.com/lhl77/Typecho-Plugin-PicUp/releases)
2. Extract and rename the folder to `PicUp`
3. Upload it to `usr/plugins/` in your Typecho installation
4. Go to **Admin** → **Console** → **Plugins** → enable **PicUp**

### 3. Configure PicUp

1. Go to **Admin** → **Settings** → **PicUp**
2. In the Config Edit box, add a profile with Driver `S.EE` and paste your API Token
3. Click **Set Global** and then click **Save**

![](https://i.see.you/2026/04/13/gR6c/594fbf1e0ce9721fc0e601b305fd7620.jpg)

---

## Usage

Once configured, PicUp works transparently in the background:

- **Writing a post** — insert images via the editor toolbar as usual. PicUp intercepts the upload and stores the file on S.EE, returning the public URL automatically.
- **Media Library** — upload files from **Admin** → **Media**. All files go to S.EE and the URLs are stored in Typecho's media library.

---

## Related

- [Developer API](https://s.ee/docs/developers/)
- [File Sharing](https://s.ee/docs/file-sharing/)
- [SM.MS Compatibility](https://s.ee/docs/developers/smms-compatibility/)
