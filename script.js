document.querySelectorAll("[data-modal]").forEach((boton) => {
    boton.addEventListener("click", () => {
        const modal = document.getElementById(boton.dataset.modal);
        if (modal) modal.classList.add("activo");
    });
});

document.querySelectorAll(".cerrar").forEach((boton) => {
    boton.addEventListener("click", () => boton.closest(".modal").classList.remove("activo"));
});

document.querySelectorAll(".modal").forEach((modal) => {
    modal.addEventListener("click", (evento) => {
        if (evento.target === modal) modal.classList.remove("activo");
    });
});

document.querySelectorAll(".copiar").forEach((boton) => {
    boton.addEventListener("click", async () => {
        try {
            await navigator.clipboard.writeText(boton.dataset.copiar);
            boton.textContent = "¡Copiada!";
        } catch {
            boton.textContent = "No se pudo copiar";
        }
    });
});
