/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./**/*.php",
    "./*.php",
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          navy: "#0e3a55",
          "navy-dark": "#0d2b55",
          gold: "#f2b01e",
          "gold-light": "#ffd166",
        },
        primary: {
          DEFAULT: "#4154f1",
          dark: "#2d3fc7",
        },
        /* Student dashboard – LCRC eReview prototype (#143D59, #1665A0) */
        student: {
          sidebar: "#143D59",
          accent: "#1665A0",
          "accent-hover": "#0f4d7a",
          "accent-light": "#e8f2fa",
          danger: "#dc2626",
          "danger-hover": "#b91c1c",
        },
        /* Modal / LMS accent palette */
        accent: {
          orange: "#F59E0B",
          "orange-dark": "#D97706",
          "orange-light": "#FCD34D",
          blue: "#1F58C3",
          "blue-dark": "#1E40AF",
          "blue-light": "#3B82F6",
        },
      },
      fontFamily: {
        sans: ["Nunito", "Segoe UI", "sans-serif"],
      },
      boxShadow: {
        card: "0 2px 10px rgba(0, 0, 0, 0.05)",
        "card-lg": "0 10px 24px rgba(0, 0, 0, 0.06)",
        "student-card": "0 1px 3px rgba(20, 61, 89, 0.08), 0 4px 12px rgba(20, 61, 89, 0.06)",
        "student-card-hover": "0 4px 12px rgba(20, 61, 89, 0.1), 0 8px 24px rgba(20, 61, 89, 0.08)",
        modal: "0 20px 50px rgba(0, 0, 0, 0.12)",
        "modal-xl": "0 25px 60px rgba(0, 0, 0, 0.15), 0 0 0 1px rgba(0, 0, 0, 0.04)",
        glass: "0 25px 60px rgba(0,0,0,0.25), 0 0 0 1px rgba(255,255,255,0.06), inset 0 1px 0 0 rgba(255,255,255,0.12)",
      },
      keyframes: {
        "slide-up-fade": {
          "0%": { opacity: "0", transform: "translateY(14px)" },
          "100%": { opacity: "1", transform: "translateY(0)" },
        },
        "build-up": {
          "0%": { opacity: "0", transform: "translateY(24px)" },
          "100%": { opacity: "1", transform: "translateY(0)" },
        },
      },
      animation: {
        "build-up": "build-up 0.7s ease-out both",
        "build-up-1": "build-up 0.7s ease-out 0.1s both",
        "build-up-2": "build-up 0.7s ease-out 0.2s both",
        "build-up-3": "build-up 0.7s ease-out 0.3s both",
        "build-up-4": "build-up 0.7s ease-out 0.4s both",
        "build-up-5": "build-up 0.7s ease-out 0.5s both",
        "slide-up-fade": "slide-up-fade 0.45s ease-out both",
        "slide-up-fade-1": "slide-up-fade 0.45s ease-out 0.05s both",
        "slide-up-fade-2": "slide-up-fade 0.45s ease-out 0.10s both",
        "slide-up-fade-3": "slide-up-fade 0.45s ease-out 0.15s both",
        "slide-up-fade-4": "slide-up-fade 0.45s ease-out 0.20s both",
        "slide-up-fade-5": "slide-up-fade 0.45s ease-out 0.25s both",
        "slide-up-fade-6": "slide-up-fade 0.45s ease-out 0.30s both",
        "slide-up-fade-7": "slide-up-fade 0.45s ease-out 0.35s both",
        "slide-up-fade-8": "slide-up-fade 0.45s ease-out 0.40s both",
        "slide-up-fade-9": "slide-up-fade 0.45s ease-out 0.45s both",
        "slide-up-fade-10": "slide-up-fade 0.45s ease-out 0.50s both",
      },
    },
  },
  plugins: [],
};
