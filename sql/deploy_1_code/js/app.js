document.addEventListener('click', function (e) {
    const dropdowns = document.querySelectorAll('.dropdown');

    dropdowns.forEach(drop => {
        const toggle = drop.querySelector('.dropdown-toggle');

        if (toggle && toggle.contains(e.target)) {
            const isOpen = drop.classList.contains('open');

            dropdowns.forEach(d => d.classList.remove('open'));

            if (!isOpen) {
                drop.classList.add('open');
            }
        } else if (!drop.contains(e.target)) {
            drop.classList.remove('open');
        }
    });
});