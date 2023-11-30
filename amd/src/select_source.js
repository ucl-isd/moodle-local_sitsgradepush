export const init = (courseid, mabid) => {
    // Add an event listener to the select existing activity card.
    // When the user clicks on the card, redirect to the existing activity page.
    document.getElementById('existing-activity').addEventListener('click', (event) => {
        event.preventDefault();
        window.location.href =
            `/local/sitsgradepush/select_source.php?courseid=${courseid}&mabid=${mabid}&source=existing`;
    });
};
