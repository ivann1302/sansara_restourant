import { defineConfig } from "astro/config";
import react from "@astrojs/react";

const isDev = process.env.NODE_ENV === "development";

export default defineConfig({
  site: "https://ivann1302.github.io",
  base: isDev ? "/" : "/sansara_restourant",
  integrations: [react()],
});
