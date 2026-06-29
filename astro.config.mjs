import { defineConfig } from "astro/config";
import react from "@astrojs/react";

export default defineConfig({
  site: "https://ivann1302.github.io",
  base: "/sansara_restourant",
  integrations: [react()],
});
