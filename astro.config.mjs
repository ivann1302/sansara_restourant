import { defineConfig } from "astro/config";

const isDev = process.env.NODE_ENV === "development";

export default defineConfig({
  site: "https://ivann1302.github.io",
  base: isDev ? "/" : "/sansara_restourant",
});
