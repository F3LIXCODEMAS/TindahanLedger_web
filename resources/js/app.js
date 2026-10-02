import './bootstrap';

const passwordToggle = document.querySelector('[data-password-toggle]');
const passwordInput = document.querySelector('#password');

if (passwordToggle instanceof HTMLButtonElement && passwordInput instanceof HTMLInputElement) {
	passwordToggle.addEventListener('click', () => {
		const isVisible = passwordInput.type === 'text';

		passwordInput.type = isVisible ? 'password' : 'text';
		passwordToggle.textContent = isVisible ? 'Show' : 'Hide';
		passwordToggle.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
		passwordToggle.setAttribute('aria-pressed', String(!isVisible));
	});
}

const loginForm = document.querySelector('[data-login-form]');

if (loginForm instanceof HTMLFormElement) {
	const emailInput = loginForm.querySelector('#email');
	const submitButton = loginForm.querySelector('[data-login-submit]');
	const submitLabel = loginForm.querySelector('[data-submit-label]');
	const loadingLabel = loginForm.querySelector('[data-loading-label]');

	if (emailInput instanceof HTMLInputElement) {
		emailInput.addEventListener('invalid', () => {
			if (emailInput.validity.valueMissing) {
				emailInput.setCustomValidity('Please enter your email.');
			} else if (emailInput.validity.typeMismatch) {
				emailInput.setCustomValidity('Please enter a valid email address.');
			}
		});

		emailInput.addEventListener('input', () => emailInput.setCustomValidity(''));
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
