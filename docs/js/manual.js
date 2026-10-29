/* ========================================
   WordPress Plugin Manual - Common Scripts
   ======================================== */

(function () {
    "use strict";

    /* ========================================
       1. Lightbox
       ======================================== */

    function initLightbox() {
        var lightbox = document.querySelector(".lightbox");
        if (!lightbox || !lightbox.querySelector("img")) {
            // Create or rebuild lightbox element
            if (lightbox) lightbox.remove();
            lightbox = document.createElement("div");
            lightbox.className = "lightbox";
            lightbox.innerHTML =
                '<button class="lightbox-close" aria-label="Close">&times;</button>' +
                '<img src="" alt="Enlarged screenshot">';
            document.body.appendChild(lightbox);
        }

        var lightboxImg = lightbox.querySelector("img");
        var closeBtn = lightbox.querySelector(".lightbox-close");

        // Open lightbox when clicking a trigger image
        document.addEventListener("click", function (e) {
            var trigger = e.target.closest(".lightbox-trigger");
            if (!trigger) return;

            e.preventDefault();
            var src = trigger.getAttribute("data-full") || trigger.getAttribute("src");
            if (src) {
                lightboxImg.src = src;
                lightbox.classList.add("active");
                document.body.style.overflow = "hidden";
            }
        });

        // Close on close-button click
        closeBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            closeLightbox();
        });

        // Close on overlay click (but not on the image itself)
        lightbox.addEventListener("click", function (e) {
            if (e.target === lightbox) {
                closeLightbox();
            }
        });

        // Close on ESC key
        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape" && lightbox.classList.contains("active")) {
                closeLightbox();
            }
        });

        function closeLightbox() {
            lightbox.classList.remove("active");
            document.body.style.overflow = "";
        }
    }

    /* ========================================
       2. Sidebar Active State
       ======================================== */

    function initSidebarActiveState() {
        var currentPage = window.location.pathname.split("/").pop() || "index.html";
        var sidebarLinks = document.querySelectorAll(".sidebar ul li a");

        sidebarLinks.forEach(function (link) {
            var href = link.getAttribute("href");
            if (!href) return;

            var linkPage = href.split("/").pop().split("#")[0];

            if (linkPage === currentPage) {
                link.parentElement.classList.add("active");
            }
        });
    }

    /* ========================================
       3. Mobile Hamburger Toggle
       ======================================== */

    function initHamburgerToggle() {
        var hamburger = document.querySelector(".hamburger-btn");
        var sidebar = document.querySelector(".sidebar");

        if (!hamburger || !sidebar) return;

        hamburger.addEventListener("click", function () {
            sidebar.classList.toggle("open");

            // Update button label
            var isOpen = sidebar.classList.contains("open");
            hamburger.setAttribute("aria-expanded", isOpen);
            hamburger.innerHTML = isOpen ? "&times;" : "&#9776;";
        });

        // Close sidebar when a link inside it is clicked (mobile)
        sidebar.addEventListener("click", function (e) {
            if (e.target.tagName === "A") {
                sidebar.classList.remove("open");
                hamburger.setAttribute("aria-expanded", "false");
                hamburger.innerHTML = "&#9776;";
            }
        });
    }

    /* ========================================
       4. Smooth Scroll for Anchor Links
       ======================================== */

    function initSmoothScroll() {
        document.addEventListener("click", function (e) {
            var link = e.target.closest('a[href^="#"]');
            if (!link) return;

            var targetId = link.getAttribute("href").slice(1);
            if (!targetId) return;

            var target = document.getElementById(targetId);
            if (!target) return;

            e.preventDefault();

            target.scrollIntoView({
                behavior: "smooth",
                block: "start"
            });

            // Update URL hash without jumping
            if (history.pushState) {
                history.pushState(null, null, "#" + targetId);
            }
        });
    }

    /* ========================================
       5. Initialise All
       ======================================== */

    function init() {
        initLightbox();
        initSidebarActiveState();
        initHamburgerToggle();
        initSmoothScroll();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
