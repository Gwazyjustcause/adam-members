(function () {
	const STORAGE_KEY = "adamMemberRewardsSort";

	function compareCards(sortKey) {
		return function (left, right) {
			const leftPoints = parseInt(left.dataset.rewardPoints || "0", 10);
			const rightPoints = parseInt(right.dataset.rewardPoints || "0", 10);
			const leftRarity = parseInt(left.dataset.rewardRarity || "0", 10);
			const rightRarity = parseInt(right.dataset.rewardRarity || "0", 10);
			const leftType = left.dataset.rewardType || "";
			const rightType = right.dataset.rewardType || "";
			const leftName = left.dataset.rewardName || "";
			const rightName = right.dataset.rewardName || "";
			const leftIndex = parseInt(left.dataset.rewardIndex || "0", 10);
			const rightIndex = parseInt(right.dataset.rewardIndex || "0", 10);

			let result = 0;

			switch (sortKey) {
				case "points_desc":
					result = rightPoints - leftPoints;
					break;
				case "rarity_desc":
					result = rightRarity - leftRarity;
					break;
				case "rarity_asc":
					result = leftRarity - rightRarity;
					break;
				case "type":
					result = leftType.localeCompare(rightType, "pt");
					break;
				case "name":
					result = leftName.localeCompare(rightName, "pt");
					break;
				case "points_asc":
				default:
					result = leftPoints - rightPoints;
					break;
			}

			if (result !== 0) {
				if (sortKey === "type") {
					return result;
				}

				return result;
			}

			result = leftName.localeCompare(rightName, "pt");

			if (result !== 0) {
				return result;
			}

			return leftIndex - rightIndex;
		};
	}

	function sortRewardList(list, sortKey) {
		const cards = Array.from(list.querySelectorAll(".adam-rewards-catalogue-card"));

		cards.sort(compareCards(sortKey));
		cards.forEach((card) => list.appendChild(card));
	}

	function initRewardsSorting() {
		const select = document.querySelector("[data-adam-rewards-sort]");
		const lists = Array.from(document.querySelectorAll('[data-adam-reward-list="points"]'));

		if (!select || lists.length === 0) {
			return;
		}

		const savedSort = window.localStorage ? window.localStorage.getItem(STORAGE_KEY) : "";
		const initialSort = savedSort && select.querySelector(`option[value="${savedSort}"]`) ? savedSort : select.value;

		select.value = initialSort;
		lists.forEach((list) => sortRewardList(list, initialSort));

		select.addEventListener("change", function () {
			const sortKey = select.value;

			lists.forEach((list) => sortRewardList(list, sortKey));

			if (window.localStorage) {
				window.localStorage.setItem(STORAGE_KEY, sortKey);
			}
		});
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", initRewardsSorting);
	} else {
		initRewardsSorting();
	}
})();
