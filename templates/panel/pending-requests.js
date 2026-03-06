document.addEventListener("click", async (e) => {

    const allow = e.target.closest(".allow-btn");
    const deny = e.target.closest(".reject-btn");

    if (!allow && !deny) return;

    const btn = allow || deny;
    const uuid = btn.dataset.uuid;

    const page = window.panel.page.id;
    const action = allow ? "approve" : "deny";

    try {
        const response = await window.panel.api.post(
            `/pages/${page}/mmh-signage/${action}`,
            { uuid }
        );

        window.panel.notification.success("Aktion ausgeführt");
        window.location.reload();

    } catch (err) {
        window.panel.notification.error("Fehler bei der Anfrage");
    }

});