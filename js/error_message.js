function wpAdminNotice(message, type = "success") {
  const container = document.getElementById("gesfaturacao-notices");
  if (!container) return;

  // Build the same HTML that settings_errors() would output:
  const notice = document.createElement("div");
  notice.className = `notice notice-${type} is-dismissible`;
  notice.innerHTML = `
    <p>${message}</p>
    <button type="button" class="notice-dismiss">
      <span class="screen-reader-text">Dismiss this notice.</span>
    </button>
  `;

  container.appendChild(notice);

  // Dismiss on click of the “X”
  jQuery(notice).on("click", ".notice-dismiss", function () {
    jQuery(this).closest(".notice").fadeOut();
  });

  // Auto‐dismiss after 10 seconds
  setTimeout(() => {
    jQuery(notice).fadeOut();
  }, 10000);
}
