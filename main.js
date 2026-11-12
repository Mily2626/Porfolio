const header = document.querySelector(".site-header");
const navToggle = document.querySelector(".nav-toggle");
const navLinks = document.querySelector(".nav-links");
const navLinkItems = Array.from(document.querySelectorAll(".nav-link"));
const yearEl = document.getElementById("year");

if (yearEl) {
  yearEl.textContent = new Date().getFullYear();
}

const updateHeader = () => {
  if (header) {
    header.classList.toggle("is-scrolled", window.scrollY > 8);
  }
};

updateHeader();
window.addEventListener("scroll", updateHeader, { passive: true });

const closeMenu = () => {
  if (!navToggle || !navLinks) return;
  navLinks.classList.remove("is-open");
  navToggle.setAttribute("aria-expanded", "false");
};

if (navToggle && navLinks) {
  navToggle.addEventListener("click", () => {
    const isOpen = navLinks.classList.toggle("is-open");
    navToggle.setAttribute("aria-expanded", String(isOpen));
  });

  navLinkItems.forEach((link) => {
    link.addEventListener("click", closeMenu);
  });

  document.addEventListener("click", (event) => {
    if (!navLinks.classList.contains("is-open")) return;
    if (navLinks.contains(event.target) || navToggle.contains(event.target)) return;
    closeMenu();
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") closeMenu();
  });
}

const tabs = Array.from(document.querySelectorAll('[role="tab"]'));

const selectTab = (tab, moveFocus = false) => {
  tabs.forEach((item) => {
    const isSelected = item === tab;
    item.setAttribute("aria-selected", String(isSelected));
    item.tabIndex = isSelected ? 0 : -1;

    const panelId = item.getAttribute("aria-controls");
    const panel = panelId ? document.getElementById(panelId) : null;
    if (panel) {
      panel.hidden = !isSelected;
      panel.classList.toggle("is-active", isSelected);
    }
  });

  if (moveFocus) tab.focus();
};

tabs.forEach((tab, index) => {
  tab.addEventListener("click", () => selectTab(tab));

  tab.addEventListener("keydown", (event) => {
    let nextIndex = null;

    if (event.key === "ArrowRight") nextIndex = (index + 1) % tabs.length;
    if (event.key === "ArrowLeft") nextIndex = (index - 1 + tabs.length) % tabs.length;
    if (event.key === "Home") nextIndex = 0;
    if (event.key === "End") nextIndex = tabs.length - 1;

    if (nextIndex === null) return;
    event.preventDefault();
    selectTab(tabs[nextIndex], true);
  });
});

const lightbox = document.getElementById("lightbox");
const lightboxImg = document.getElementById("lightbox-img");
const lightboxCaption = document.getElementById("lightbox-caption");
const lightboxClose = document.querySelector(".lightbox-close");
const lightboxPrev = document.querySelector(".lightbox-prev");
const lightboxNext = document.querySelector(".lightbox-next");
const galleryItems = Array.from(document.querySelectorAll("[data-lightbox]"));

let lightboxItems = [];
let lightboxIndex = 0;

const renderLightbox = () => {
  const item = lightboxItems[lightboxIndex];
  if (!item || !lightboxImg) return;

  const itemImg = item.querySelector("img");
  const caption = item.getAttribute("data-caption") || (itemImg ? itemImg.alt : "");

  lightboxImg.src = item.getAttribute("href");
  lightboxImg.alt = itemImg ? itemImg.alt : "";

  if (lightboxCaption) {
    lightboxCaption.textContent = `${caption} · ${lightboxIndex + 1} / ${lightboxItems.length}`;
  }
};

const openLightbox = (item) => {
  if (!lightbox) return;

  const gallery = item.closest("[data-gallery]");
  lightboxItems = gallery ? Array.from(gallery.querySelectorAll("[data-lightbox]")) : [item];
  lightboxIndex = Math.max(0, lightboxItems.indexOf(item));

  renderLightbox();
  document.body.classList.add("no-scroll");

  if (typeof lightbox.showModal === "function") {
    lightbox.showModal();
  } else {
    lightbox.setAttribute("open", "");
  }
};

const closeLightbox = () => {
  if (!lightbox) return;

  if (typeof lightbox.close === "function" && lightbox.open) {
    lightbox.close();
  } else {
    lightbox.removeAttribute("open");
    document.body.classList.remove("no-scroll");
  }
};

const stepLightbox = (direction) => {
  if (!lightboxItems.length) return;
  lightboxIndex = (lightboxIndex + direction + lightboxItems.length) % lightboxItems.length;
  renderLightbox();
};

galleryItems.forEach((item) => {
  item.addEventListener("click", (event) => {
    event.preventDefault();
    openLightbox(item);
  });
});

if (lightbox) {
  lightboxClose?.addEventListener("click", closeLightbox);
  lightboxPrev?.addEventListener("click", () => stepLightbox(-1));
  lightboxNext?.addEventListener("click", () => stepLightbox(1));

  lightbox.addEventListener("click", (event) => {
    if (event.target === lightbox) closeLightbox();
  });

  lightbox.addEventListener("close", () => {
    document.body.classList.remove("no-scroll");
    if (lightboxImg) lightboxImg.removeAttribute("src");
  });

  document.addEventListener("keydown", (event) => {
    if (!lightbox.open) return;
    if (event.key === "ArrowRight") {
      event.preventDefault();
      stepLightbox(1);
    }
    if (event.key === "ArrowLeft") {
      event.preventDefault();
      stepLightbox(-1);
    }
  });
}

const revealElements = Array.from(document.querySelectorAll(".reveal"));
const prefersReducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

if ("IntersectionObserver" in window && !prefersReducedMotion) {
  const revealObserver = new IntersectionObserver(
    (entries, observer) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add("is-visible");
        observer.unobserve(entry.target);
      });
    },
    { threshold: 0.12, rootMargin: "0px 0px -8% 0px" }
  );

  revealElements.forEach((element) => revealObserver.observe(element));
} else {
  revealElements.forEach((element) => element.classList.add("is-visible"));
}

const sections = Array.from(document.querySelectorAll("main section[id]"));

if ("IntersectionObserver" in window && sections.length) {
  const sectionObserver = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        navLinkItems.forEach((link) => {
          link.classList.toggle("is-active", link.getAttribute("href") === `#${entry.target.id}`);
        });
      });
    },
    { rootMargin: "-45% 0px -50% 0px", threshold: 0 }
  );

  sections.forEach((section) => sectionObserver.observe(section));
}
