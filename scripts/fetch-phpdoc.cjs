const fs = require("fs"),
  https = require("https"),
  path = require("path");
const dest = path.join(__dirname, "../tools/phpDocumentor.phar");
if (fs.existsSync(dest)) process.exit(0);
fs.mkdirSync(path.dirname(dest), { recursive: true });

function download(url) {
  https
    .get(url, (res) => {
      if (
        res.statusCode >= 300 &&
        res.statusCode < 400 &&
        res.headers.location
      ) {
        download(res.headers.location);
        return;
      }
      if (res.statusCode !== 200) {
        console.error("Download failed:", res.statusCode);
        process.exit(1);
      }
      const out = fs.createWriteStream(dest);
      res.pipe(out).on("finish", () => {
        fs.chmodSync(dest, 0o755);
        console.log("phpDocumentor.phar ready");
      });
    })
    .on("error", (e) => {
      const errMsg = (e && e.message ? e.message : String(e)).replace(
        /[\r\n]+/g,
        " ",
      );
      console.error("Download error:", errMsg);
      process.exit(1);
    });
}

download("https://phpdoc.org/phpDocumentor.phar");
