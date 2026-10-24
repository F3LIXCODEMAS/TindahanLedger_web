import './bootstrap';

const passwordInput = document.querySelector('#password');

// Each show/hide button controls the password input named by its aria-controls.
document.querySelectorAll('[data-password-toggle]').forEach((passwordToggle) => {
	const controlledInput = document.getElementById(passwordToggle.getAttribute('aria-controls') ?? '');

	if (!(passwordToggle instanceof HTMLButtonElement) || !(controlledInput instanceof HTMLInputElement)) {
		return;
	}

	passwordToggle.addEventListener('click', () => {
		const isVisible = controlledInput.type === 'text';

		controlledInput.type = isVisible ? 'password' : 'text';
		passwordToggle.textContent = isVisible ? 'Show' : 'Hide';
		passwordToggle.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
		passwordToggle.setAttribute('aria-pressed', String(!isVisible));
	});
});

// Create Store: show only the owner fields for the chosen owner type. A
// disabled fieldset is neither validated by the browser nor submitted.
const ownerTypeInputs = document.querySelectorAll('[data-owner-type]');
const ownerSections = document.querySelectorAll('[data-owner-section]');

ownerTypeInputs.forEach((ownerTypeInput) => {
	if (!(ownerTypeInput instanceof HTMLInputElement)) {
		return;
	}

	ownerTypeInput.addEventListener('change', () => {
		if (!ownerTypeInput.checked) {
			return;
		}

		ownerSections.forEach((ownerSection) => {
			if (!(ownerSection instanceof HTMLFieldSetElement)) {
				return;
			}

			const isChosen = ownerSection.dataset.ownerSection === ownerTypeInput.value;
			ownerSection.hidden = !isChosen;
			ownerSection.disabled = !isChosen;
		});
	});
});

const loginForm = document.querySelector('[data-login-form]');

if (loginForm instanceof HTMLFormElement) {
	const usernameInput = loginForm.querySelector('#username');
	const submitButton = loginForm.querySelector('[data-login-submit]');
	const submitLabel = loginForm.querySelector('[data-submit-label]');
	const loadingLabel = loginForm.querySelector('[data-loading-label]');

	if (usernameInput instanceof HTMLInputElement) {
		usernameInput.addEventListener('invalid', () => {
			if (usernameInput.validity.valueMissing) {
				usernameInput.setCustomValidity('Please enter your username.');
			}
		});

		usernameInput.addEventListener('input', () => usernameInput.setCustomValidity(''));
	}

	if (passwordInput instanceof HTMLInputElement) {
		passwordInput.addEventListener('invalid', () => {
			if (passwordInput.validity.valueMissing) {
				passwordInput.setCustomValidity('Please enter your password.');
			}
		});

		passwordInput.addEventListener('input', () => passwordInput.setCustomValidity(''));
	}

	loginForm.addEventListener('submit', () => {
		if (
			submitButton instanceof HTMLButtonElement
			&& submitLabel instanceof HTMLElement
			&& loadingLabel instanceof HTMLElement
		) {
			submitButton.disabled = true;
			submitButton.setAttribute('aria-busy', 'true');
			submitLabel.hidden = true;
			loadingLabel.hidden = false;
		}
	});
}

document.querySelectorAll('[data-customer-form]').forEach((customerForm) => {
	if (!(customerForm instanceof HTMLFormElement)) {
		return;
	}

	customerForm.querySelectorAll('[data-trim-required]').forEach((field) => {
		if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement)) {
			return;
		}

		const validateTrimmedValue = () => {
			field.setCustomValidity(field.value.trim() === '' ? field.dataset.emptyMessage ?? 'This field is required.' : '');
		};

		field.addEventListener('input', validateTrimmedValue);
		field.addEventListener('invalid', validateTrimmedValue);
		field.addEventListener('blur', () => {
			field.value = field.value.trim();
			validateTrimmedValue();
		});
	});

	const emailField = customerForm.querySelector('[data-normalize-email]');

	if (emailField instanceof HTMLInputElement) {
		emailField.addEventListener('blur', () => {
			emailField.value = emailField.value.trim().toLowerCase();
		});
	}
});

const creditForm = document.querySelector('[data-credit-form]');

if (creditForm instanceof HTMLFormElement) {
	const customerSearch = creditForm.querySelector('[data-credit-customer-search]');
	const customerSelect = creditForm.querySelector('#customer_id');
	const searchStatus = creditForm.querySelector('[data-credit-search-status]');
	const creditLimit = creditForm.querySelector('[data-credit-limit]');
	const currentBalance = creditForm.querySelector('[data-credit-balance]');
	const availableCredit = creditForm.querySelector('[data-credit-available]');
	const submitButton = creditForm.querySelector('[data-credit-submit]');
	const submitLabel = creditForm.querySelector('[data-credit-submit-label]');
	const savingLabel = creditForm.querySelector('[data-credit-saving-label]');
	let isSubmitting = false;

	if (
		customerSearch instanceof HTMLInputElement
		&& customerSelect instanceof HTMLSelectElement
		&& searchStatus instanceof HTMLElement
	) {
		const customerOptions = Array.from(customerSelect.querySelectorAll('[data-credit-customer-option]'));

		customerSearch.addEventListener('input', () => {
			const searchTerm = customerSearch.value.trim().toLocaleLowerCase();
			let visibleCount = 0;

			customerOptions.forEach((option) => {
				const isMatch = option.textContent?.toLocaleLowerCase().includes(searchTerm) ?? false;
				option.hidden = !isMatch;
				visibleCount += isMatch ? 1 : 0;
			});

			searchStatus.textContent = searchTerm === ''
				? `${customerOptions.length} customers available`
				: `${visibleCount} matching ${visibleCount === 1 ? 'customer' : 'customers'}`;
		});
	}

	if (
		customerSelect instanceof HTMLSelectElement
		&& creditLimit instanceof HTMLElement
		&& currentBalance instanceof HTMLElement
		&& availableCredit instanceof HTMLElement
	) {
		customerSelect.addEventListener('change', async () => {
			const selectedOption = customerSelect.selectedOptions[0];
			const summaryUrl = selectedOption?.dataset.summaryUrl;

			if (!summaryUrl) {
				creditLimit.textContent = '—';
				currentBalance.textContent = '—';
				availableCredit.textContent = '—';
				return;
			}

			creditLimit.textContent = 'Loading...';
			currentBalance.textContent = 'Loading...';
			availableCredit.textContent = 'Loading...';

			try {
				const response = await fetch(summaryUrl, {
					headers: {
						Accept: 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
				});

				if (!response.ok) {
					throw new Error('Unable to load customer balances.');
				}

				const summary = await response.json();
				creditLimit.textContent = `₱${summary.credit_limit}`;
				currentBalance.textContent = `₱${summary.outstanding_balance}`;
				availableCredit.textContent = `₱${summary.available_credit}`;
			} catch {
				creditLimit.textContent = 'Unavailable';
				currentBalance.textContent = 'Unavailable';
				availableCredit.textContent = 'Unavailable';
			}
		});
	}

	creditForm.addEventListener('submit', (event) => {
		if (isSubmitting) {
			event.preventDefault();
			return;
		}

		isSubmitting = true;

		if (
			submitButton instanceof HTMLButtonElement
			&& submitLabel instanceof HTMLElement
			&& savingLabel instanceof HTMLElement
		) {
			submitButton.disabled = true;
			submitButton.setAttribute('aria-busy', 'true');
			submitLabel.hidden = true;
			savingLabel.hidden = false;
		}
	});
}

// Product picker for Record Credit (DUMS-36) and Record Cash Purchase
// (DUMS-37). Totals use whole centavos and thousandths (BigInt) with
// half-up rounding, matching the server. In credit mode the subtotal is a
// reference only and the entered amount is never changed. In cash mode each
// row has a sale-only price and the reviewed total is submitted as
// expected_total so the server can reject a stale or altered total.
const creditProducts = document.querySelector('[data-credit-products]');

if (creditProducts instanceof HTMLElement) {
	const productSearch = creditProducts.querySelector('[data-credit-product-search]');
	const productStatus = creditProducts.querySelector('[data-credit-product-status]');
	const productResults = creditProducts.querySelector('[data-credit-product-results]');
	const itemList = creditProducts.querySelector('[data-credit-item-list]');
	const itemTemplate = creditProducts.querySelector('[data-credit-item-template]');
	const itemSummary = creditProducts.querySelector('[data-credit-item-summary]');
	const subtotalLabel = creditProducts.querySelector('[data-credit-item-subtotal-label]');
	const subtotalOutput = creditProducts.querySelector('[data-credit-item-subtotal]');
	const differenceOutput = creditProducts.querySelector('[data-credit-item-difference]');
	const amountInput = document.querySelector('#amount');
	const expectedTotalInput = creditProducts.querySelector('[data-expected-total]');
	const isCashSale = creditProducts.dataset.mode === 'cash';
	const quantityMessage = 'Enter a quantity greater than zero with up to 3 decimal places.';
	const priceMessage = 'Enter a price of zero or more with no more than two decimal places.';

	if (
		productSearch instanceof HTMLInputElement
		&& productStatus instanceof HTMLElement
		&& productResults instanceof HTMLElement
		&& itemList instanceof HTMLElement
		&& itemTemplate instanceof HTMLTemplateElement
		&& itemSummary instanceof HTMLElement
		&& subtotalLabel instanceof HTMLElement
		&& subtotalOutput instanceof HTMLElement
		&& differenceOutput instanceof HTMLElement
	) {
		let nextIndex = Number(itemList.dataset.nextIndex ?? '0');
		let lastResults = [];
		let searchTimer;
		let searchController;

		const toCents = (value) => {
			const amount = String(value ?? '').trim();

			if (!/^\d+(?:\.\d{1,2})?$/.test(amount)) {
				return null;
			}

			const [whole, fraction = ''] = amount.split('.');

			return (BigInt(whole) * 100n) + BigInt(fraction.padEnd(2, '0'));
		};

		const toThousandths = (value) => {
			const quantity = String(value ?? '').trim();

			if (!/^\d{1,4}(?:\.\d{1,3})?$/.test(quantity)) {
				return null;
			}

			const [whole, fraction = ''] = quantity.split('.');
			const thousandths = (BigInt(whole) * 1000n) + BigInt(fraction.padEnd(3, '0'));

			return thousandths > 0n && thousandths <= 1000000n ? thousandths : null;
		};

		const formatCents = (cents) => {
			const absoluteCents = cents < 0n ? -cents : cents;

			return `₱${absoluteCents / 100n}.${(absoluteCents % 100n).toString().padStart(2, '0')}`;
		};

		const itemRows = () => Array.from(itemList.querySelectorAll('[data-credit-item]'));
		const selectedProductIds = () => new Set(itemRows().map((row) => row.dataset.productId));

		// Optional "often bought together" suggestions (DUMS-40). They never block entry:
		// failures only show a message, and Add uses the normal addProduct() path.
		const recommendationPanel = creditProducts.querySelector('[data-credit-recommendations]');
		const recommendationStatus = creditProducts.querySelector('[data-credit-recommendation-status]');
		const recommendationList = creditProducts.querySelector('[data-credit-recommendation-list]');
		const recommendationMessages = {
			no_active_model: 'Suggestions appear once this store has recorded enough sales.',
			no_matches: 'No suggestions for these products yet.',
			failed: 'Suggestions are unavailable right now. You can keep adding products as usual.',
		};
		let recommendationTimer;
		let recommendationController;
		let recommendationRequest = 0;
		let lastRecommendationKey = null;

		const renderRecommendations = (recommendations) => {
			if (!(recommendationList instanceof HTMLElement)) {
				return;
			}

			recommendationList.replaceChildren();

			recommendations.slice(0, 3).forEach((product) => {
				const item = document.createElement('li');
				const text = document.createElement('div');
				const name = document.createElement('strong');
				const explanation = document.createElement('span');
				const addButton = document.createElement('button');

				item.className = 'credit-recommendation';
				text.className = 'credit-recommendation-text';
				name.textContent = product.name;
				explanation.textContent = product.explanation;
				addButton.type = 'button';
				addButton.className = 'credit-recommendation-add';
				addButton.textContent = 'Add';
				addButton.setAttribute('aria-label', `Add ${product.name}`);
				addButton.addEventListener('click', () => addProduct({ id: product.id, name: product.name, unit_price: product.unit_price }));
				text.append(name, explanation);
				item.append(text, addButton);
				recommendationList.append(item);
			});

			recommendationList.hidden = recommendations.length === 0;
		};

		const loadRecommendations = async () => {
			if (!(recommendationPanel instanceof HTMLElement) || !(recommendationStatus instanceof HTMLElement)) {
				return;
			}

			const productIds = Array.from(selectedProductIds())
				.filter((id) => /^\d+$/.test(id ?? ''))
				.sort((left, right) => Number(left) - Number(right));
			const basketKey = productIds.join(',');

			if (basketKey === lastRecommendationKey) {
				return;
			}

			lastRecommendationKey = basketKey;
			recommendationController?.abort();
			const request = ++recommendationRequest;

			if (productIds.length === 0) {
				recommendationPanel.hidden = true;
				recommendationPanel.removeAttribute('aria-busy');
				recommendationStatus.textContent = '';
				renderRecommendations([]);
				return;
			}

			recommendationController = new AbortController();
			recommendationPanel.hidden = false;
			recommendationPanel.setAttribute('aria-busy', 'true');
			recommendationStatus.textContent = 'Loading suggestions...';

			try {
				const recommendationsUrl = new URL(recommendationPanel.dataset.recommendationsUrl ?? '', window.location.origin);
				productIds.forEach((id) => recommendationsUrl.searchParams.append('product_ids[]', id));

				const response = await fetch(recommendationsUrl, {
					headers: {
						Accept: 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
					signal: recommendationController.signal,
				});

				if (!response.ok) {
					throw new Error('Unable to load suggestions.');
				}

				const { status, recommendations } = await response.json();

				if (request !== recommendationRequest) {
					return;
				}

				const currentIds = selectedProductIds();
				const shown = (Array.isArray(recommendations) ? recommendations : []).filter((product) => !currentIds.has(String(product.id)));
				renderRecommendations(shown);
				recommendationStatus.textContent = shown.length > 0 ? '' : (recommendationMessages[status] ?? recommendationMessages.no_matches);
			} catch (error) {
				if ((error instanceof DOMException && error.name === 'AbortError') || request !== recommendationRequest) {
					return;
				}

				lastRecommendationKey = null;
				renderRecommendations([]);
				recommendationStatus.textContent = recommendationMessages.failed;
			} finally {
				if (request === recommendationRequest) {
					recommendationPanel.removeAttribute('aria-busy');
				}
			}
		};

		const scheduleRecommendations = () => {
			clearTimeout(recommendationTimer);
			recommendationTimer = setTimeout(loadRecommendations, 300);
		};

		const setExpectedTotal = (value) => {
			if (expectedTotalInput instanceof HTMLInputElement) {
				expectedTotalInput.value = value;
			}
		};

		const updateTotals = () => {
			const rows = itemRows();
			let subtotal = 0n;
			let rowsWithoutPrice = 0;
			let rowsWithInvalidQuantity = 0;

			itemSummary.hidden = rows.length === 0;
			setExpectedTotal('');

			rows.forEach((row) => {
				const quantityInput = row.querySelector('[data-credit-item-quantity]');
				const priceInput = row.querySelector('[data-credit-item-unit-price]');
				const totalOutput = row.querySelector('[data-credit-item-total]');
				const unitPrice = priceInput instanceof HTMLInputElement ? toCents(priceInput.value) : toCents(row.dataset.unitPrice);
				const quantity = quantityInput instanceof HTMLInputElement ? toThousandths(quantityInput.value) : null;

				if (quantityInput instanceof HTMLInputElement) {
					quantityInput.setCustomValidity(quantity === null ? quantityMessage : '');
				}

				if (priceInput instanceof HTMLInputElement) {
					priceInput.setCustomValidity(unitPrice === null ? priceMessage : '');
				}

				if (!(totalOutput instanceof HTMLElement)) {
					return;
				}

				if (quantity === null) {
					rowsWithInvalidQuantity += 1;
					totalOutput.textContent = '—';
				} else if (unitPrice === null) {
					rowsWithoutPrice += 1;
					totalOutput.textContent = isCashSale ? 'Enter price' : 'No price';
				} else {
					const lineTotal = ((unitPrice * quantity) + 500n) / 1000n;
					subtotal += lineTotal;
					totalOutput.textContent = formatCents(lineTotal);
				}
			});

			if (isCashSale) {
				if (rowsWithInvalidQuantity > 0 || rowsWithoutPrice > 0) {
					subtotalLabel.textContent = 'Total (incomplete)';
					subtotalOutput.textContent = '—';
					differenceOutput.textContent = rowsWithInvalidQuantity > 0
						? 'Fix the highlighted quantities to see the total.'
						: 'Enter a price for every product to see the total.';
					return;
				}

				subtotalLabel.textContent = 'Total';
				subtotalOutput.textContent = formatCents(subtotal);
				differenceOutput.textContent = 'Paid in cash now. This does not change any customer balance.';
				setExpectedTotal(`${subtotal / 100n}.${(subtotal % 100n).toString().padStart(2, '0')}`);
				return;
			}

			if (rowsWithInvalidQuantity > 0 || rowsWithoutPrice > 0) {
				subtotalLabel.textContent = 'Item subtotal (incomplete)';
				subtotalOutput.textContent = '—';
				differenceOutput.textContent = rowsWithInvalidQuantity > 0
					? 'Fix the highlighted quantities to see the item subtotal.'
					: `${rowsWithoutPrice} ${rowsWithoutPrice === 1 ? 'product has' : 'products have'} no price, so there is no complete subtotal. The amount above is still what the customer owes.`;
				return;
			}

			subtotalLabel.textContent = 'Item subtotal (reference)';
			subtotalOutput.textContent = formatCents(subtotal);

			const enteredAmount = amountInput instanceof HTMLInputElement ? toCents(amountInput.value) : null;

			if (enteredAmount === null) {
				differenceOutput.textContent = 'Enter the credit amount to compare it with the item subtotal.';
			} else if (enteredAmount === subtotal) {
				differenceOutput.textContent = 'The credit amount matches the item subtotal.';
			} else {
				const difference = enteredAmount - subtotal;
				differenceOutput.textContent = `The credit amount is ${formatCents(difference)} ${difference > 0n ? 'more' : 'less'} than the item subtotal. The credit amount is what will be recorded.`;
			}
		};

		const renderResults = (products) => {
			const selectedIds = selectedProductIds();
			lastResults = products;
			productResults.replaceChildren();

			products.forEach((product) => {
				const resultItem = document.createElement('li');
				const optionButton = document.createElement('button');
				const nameLabel = document.createElement('span');
				const priceLabel = document.createElement('span');
				const isSelected = selectedIds.has(String(product.id));

				optionButton.type = 'button';
				optionButton.className = 'credit-product-option';
				optionButton.disabled = isSelected;
				nameLabel.textContent = product.name;
				priceLabel.textContent = isSelected ? 'Added' : (product.unit_price === null ? 'No price' : `₱${product.unit_price}`);
				optionButton.append(nameLabel, priceLabel);
				optionButton.addEventListener('click', () => addProduct(product));
				resultItem.append(optionButton);
				productResults.append(resultItem);
			});

			productResults.hidden = products.length === 0;
		};

		function addProduct(product) {
			if (selectedProductIds().has(String(product.id))) {
				return;
			}

			const fragment = itemTemplate.content.cloneNode(true);
			const row = fragment.querySelector('[data-credit-item]');

			if (!(row instanceof HTMLElement)) {
				return;
			}

			const index = String(nextIndex);
			nextIndex += 1;
			row.dataset.productId = String(product.id);
			row.dataset.unitPrice = product.unit_price ?? '';
			row.querySelectorAll('[name]').forEach((field) => {
				field.setAttribute('name', (field.getAttribute('name') ?? '').replace('__INDEX__', index));
			});

			const productField = row.querySelector('[data-credit-item-product]');
			const nameOutput = row.querySelector('[data-credit-item-name]');
			const priceOutput = row.querySelector('[data-credit-item-price]');
			const priceField = row.querySelector('[data-credit-item-unit-price]');
			const removeButton = row.querySelector('[data-credit-item-remove]');

			if (productField instanceof HTMLInputElement) {
				productField.value = String(product.id);
			}

			if (nameOutput instanceof HTMLElement) {
				nameOutput.textContent = product.name;
			}

			if (priceField instanceof HTMLInputElement) {
				priceField.value = product.unit_price ?? '';
			}

			if (priceOutput instanceof HTMLElement) {
				if (isCashSale) {
					priceOutput.textContent = product.unit_price === null ? 'No catalog price' : `Catalog price ₱${product.unit_price}`;
				} else {
					priceOutput.textContent = product.unit_price === null ? 'No price set' : `₱${product.unit_price} each`;
				}
			}

			removeButton?.setAttribute('aria-label', `Remove ${product.name}`);
			itemList.append(fragment);
			updateTotals();
			renderResults(lastResults);
			scheduleRecommendations();
			row.querySelector('[data-credit-item-quantity]')?.focus();
		}

		const searchProducts = async () => {
			searchController?.abort();
			searchController = new AbortController();
			productStatus.textContent = 'Searching...';

			try {
				const searchUrl = new URL(creditProducts.dataset.searchUrl ?? '', window.location.origin);
				const searchTerm = productSearch.value.trim();

				if (searchTerm !== '') {
					searchUrl.searchParams.set('search', searchTerm);
				}

				const response = await fetch(searchUrl, {
					headers: {
						Accept: 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
					signal: searchController.signal,
				});

				if (!response.ok) {
					throw new Error('Unable to load products.');
				}

				const { products } = await response.json();
				renderResults(products);
				productStatus.textContent = products.length === 0
					? 'No active products match.'
					: `${products.length} ${products.length === 1 ? 'product' : 'products'} shown`;
			} catch (error) {
				if (error instanceof DOMException && error.name === 'AbortError') {
					return;
				}

				renderResults([]);
				productStatus.textContent = 'Unable to load products. Try again.';
			}
		};

		productSearch.addEventListener('input', () => {
			clearTimeout(searchTimer);
			searchTimer = setTimeout(searchProducts, 250);
		});

		productSearch.addEventListener('focus', () => {
			if (lastResults.length === 0) {
				searchProducts();
			}
		});

		productSearch.addEventListener('keydown', (event) => {
			if (event.key === 'Enter') {
				event.preventDefault();
				clearTimeout(searchTimer);
				searchProducts();
			}
		});

		itemList.addEventListener('input', (event) => {
			if (event.target instanceof HTMLElement && event.target.matches('[data-credit-item-quantity], [data-credit-item-unit-price]')) {
				updateTotals();
			}
		});

		itemList.addEventListener('click', (event) => {
			const removeButton = event.target instanceof HTMLElement ? event.target.closest('[data-credit-item-remove]') : null;

			if (removeButton) {
				removeButton.closest('[data-credit-item]')?.remove();
				updateTotals();
				renderResults(lastResults);
				scheduleRecommendations();
			}
		});

		amountInput?.addEventListener('input', updateTotals);
		updateTotals();
		loadRecommendations();
	}
}

const paymentForm = document.querySelector('[data-payment-form]');

if (paymentForm instanceof HTMLFormElement) {
	const customerSearch = paymentForm.querySelector('[data-payment-customer-search]');
	const customerSelect = paymentForm.querySelector('#customer_id');
	const searchStatus = paymentForm.querySelector('[data-payment-search-status]');
	const balanceValue = paymentForm.querySelector('[data-payment-balance]');
	const zeroBalanceMessage = paymentForm.querySelector('[data-payment-zero-message]');
	const amountInput = paymentForm.querySelector('#amount');
	const submitButton = paymentForm.querySelector('[data-payment-submit]');
	const submitLabel = paymentForm.querySelector('[data-payment-submit-label]');
	const savingLabel = paymentForm.querySelector('[data-payment-saving-label]');
	let isSubmitting = false;

	if (
		customerSearch instanceof HTMLInputElement
		&& customerSelect instanceof HTMLSelectElement
		&& searchStatus instanceof HTMLElement
	) {
		const customerOptions = Array.from(customerSelect.querySelectorAll('[data-payment-customer-option]'));

		customerSearch.addEventListener('input', () => {
			const searchTerm = customerSearch.value.trim().toLocaleLowerCase();
			let visibleCount = 0;

			customerOptions.forEach((option) => {
				const isMatch = option.textContent?.toLocaleLowerCase().includes(searchTerm) ?? false;
				option.hidden = !isMatch;
				visibleCount += isMatch ? 1 : 0;
			});

			searchStatus.textContent = searchTerm === ''
				? `${customerOptions.length} customers available`
				: `${visibleCount} matching ${visibleCount === 1 ? 'customer' : 'customers'}`;
		});
	}

	if (
		customerSelect instanceof HTMLSelectElement
		&& balanceValue instanceof HTMLElement
		&& zeroBalanceMessage instanceof HTMLElement
		&& amountInput instanceof HTMLInputElement
		&& submitButton instanceof HTMLButtonElement
	) {
		customerSelect.addEventListener('change', async () => {
			const selectedOption = customerSelect.selectedOptions[0];
			const summaryUrl = selectedOption?.dataset.summaryUrl;

			if (!summaryUrl) {
				balanceValue.textContent = '₱—';
				zeroBalanceMessage.hidden = true;
				amountInput.disabled = true;
				submitButton.disabled = true;
				return;
			}

			balanceValue.textContent = 'Loading...';
			amountInput.disabled = true;
			submitButton.disabled = true;

			try {
				const response = await fetch(summaryUrl, {
					headers: {
						Accept: 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
				});

				if (!response.ok) {
					throw new Error('Unable to load customer balance.');
				}

				const summary = await response.json();
				const hasOutstandingBalance = Number(summary.outstanding_balance) > 0;
				balanceValue.textContent = `₱${summary.outstanding_balance}`;
				zeroBalanceMessage.hidden = hasOutstandingBalance;
				amountInput.disabled = !hasOutstandingBalance;
				submitButton.disabled = !hasOutstandingBalance;
			} catch {
				balanceValue.textContent = 'Unavailable';
				zeroBalanceMessage.hidden = true;
				amountInput.disabled = true;
				submitButton.disabled = true;
			}
		});
	}

	paymentForm.addEventListener('submit', (event) => {
		if (isSubmitting) {
			event.preventDefault();
			return;
		}

		isSubmitting = true;

		if (
			submitButton instanceof HTMLButtonElement
			&& submitLabel instanceof HTMLElement
			&& savingLabel instanceof HTMLElement
		) {
			submitButton.disabled = true;
			submitButton.setAttribute('aria-busy', 'true');
			submitLabel.hidden = true;
			savingLabel.hidden = false;
		}
	});
}

document.addEventListener('submit', (event) => {
	const form = event.target;

	if (form instanceof HTMLFormElement && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
		event.preventDefault();
	}
});

const portalAccessForm = document.querySelector('[data-portal-access-form]');

if (portalAccessForm instanceof HTMLFormElement) {
	const accessToken = window.location.hash.slice(1);

	if (window.location.hash !== '') {
		window.history.replaceState(null, '', window.location.pathname + window.location.search);
	}

	const tokenInput = portalAccessForm.querySelector('input[name="token"]');
	const loadingMessage = portalAccessForm.querySelector('[data-portal-loading]');
	const instructions = document.querySelector('[data-portal-instructions]');

	if (accessToken !== '' && tokenInput instanceof HTMLInputElement) {
		tokenInput.value = accessToken;

		if (loadingMessage instanceof HTMLElement) {
			loadingMessage.hidden = false;
		}

		if (instructions instanceof HTMLElement) {
			instructions.hidden = true;
		}

		portalAccessForm.submit();
	}
}

document.querySelectorAll('[data-print-page]').forEach((printButton) => {
	printButton.addEventListener('click', () => window.print());
});

const sukiRedeemForm = document.querySelector('[data-suki-redeem-form]');

if (sukiRedeemForm instanceof HTMLFormElement) {
	const pointsInput = sukiRedeemForm.querySelector('input[name="points"]');
	const creditOutput = sukiRedeemForm.querySelector('[data-redeem-credit]');
	const balanceOutput = sukiRedeemForm.querySelector('[data-redeem-balance]');
	const submitButton = sukiRedeemForm.querySelector('[data-redeem-submit]');
	const centsPerPoint = Number(sukiRedeemForm.dataset.centsPerPoint);
	const balanceCents = Number(sukiRedeemForm.dataset.balanceCents);
	const peso = (cents) => `₱${Math.trunc(cents / 100)}.${String(cents % 100).padStart(2, '0')}`;

	// Preview only: the server recalculates and validates the redemption.
	const updatePreview = () => {
		if (!(pointsInput instanceof HTMLInputElement) || !creditOutput || !balanceOutput) {
			return;
		}

		const points = Number(pointsInput.value);

		if (!Number.isInteger(points) || points < 1) {
			creditOutput.textContent = '—';
			balanceOutput.textContent = '—';
			return;
		}

		const creditCents = points * centsPerPoint;
		creditOutput.textContent = peso(creditCents);
		balanceOutput.textContent = creditCents > balanceCents ? 'More than the balance' : peso(balanceCents - creditCents);
	};

	pointsInput?.addEventListener('input', updatePreview);
	updatePreview();

	// Runs after the confirmation handler on document; only disable once the submit really proceeds.
	window.addEventListener('submit', (event) => {
		if (event.target === sukiRedeemForm && !event.defaultPrevented && submitButton instanceof HTMLButtonElement) {
			submitButton.disabled = true;
			submitButton.textContent = 'Redeeming…';
		}
	});
}
