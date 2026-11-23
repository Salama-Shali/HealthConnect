document.addEventListener("DOMContentLoaded", () => {
  // Confirmation prompt for cancellations, reschedules, slot deletions, and missed markings (SRS 3.3.1)
  document.querySelectorAll("[data-confirm]").forEach((element) => {
    element.addEventListener("click", (event) => {
      const message = element.getAttribute("data-confirm") || "Are you sure you want to proceed?";
      if (!window.confirm(message)) {
        event.preventDefault();
      }
    });
  });

  // Client-side validation for Slot creation/editing (SRS 3.4.3: end_time > start_time)
  const slotForm = document.getElementById("slotForm");
  if (slotForm) {
    slotForm.addEventListener("submit", (event) => {
      const start = document.getElementById("start_time")?.value;
      const end = document.getElementById("end_time")?.value;
      if (start && end && end <= start) {
        event.preventDefault();
        alert("End time must be later than start time.");
      }
    });
  }
});