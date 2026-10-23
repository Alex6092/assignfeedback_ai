// Images de contrôle : node scripts/stills.mjs 120 340 900 …  (ou « nuit:+40 » = 40 images après
// le début de la scène « nuit »). Sortie : out/check/<image>.png en demi-résolution.
import { bundle } from "@remotion/bundler";
import { openBrowser, renderStill, selectComposition } from "@remotion/renderer";
import { mkdirSync, readFileSync } from "node:fs";
import path from "node:path";

const tl = JSON.parse(readFileSync(new URL("../src/timeline.json", import.meta.url), "utf8"));
const frames = process.argv.slice(2).map((a) => {
  const m = a.match(/^([a-z]+):\+?(-?\d+)$/);
  if (!m) return Number(a);
  const s = tl.scenes.find((x) => x.id === m[1]);
  if (!s) throw new Error(`scène inconnue : ${m[1]}`);
  return s.start + Number(m[2]);
});
const scale = Number(process.env.SCALE ?? 0.5);
mkdirSync("out/check", { recursive: true });
const serveUrl = await bundle({ entryPoint: path.resolve("src/index.ts") });
const inputProps = { musique: false, bruitages: false };
const browser = await openBrowser("chrome");
const composition = await selectComposition({ serveUrl, id: "Video", inputProps, puppeteerInstance: browser });
for (const frame of frames) {
  const output = `out/check/${String(frame).padStart(5, "0")}.png`;
  await renderStill({ composition, serveUrl, output, frame, inputProps, scale, puppeteerInstance: browser });
  console.log(output);
}
await browser.close({ silent: true });
