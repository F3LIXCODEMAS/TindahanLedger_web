<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | {{ config('app.name', 'TindahanLedger') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="ledger-app-page">
    <header class="ledger-topbar">
        <a class="brand-lockup ledger-brand" href="{{ route('dashboard') }}" aria-label="TindahanLedger dashboard">
            <span class="brand-mark" aria-hidden="true">TL</span>
            <span class="brand-name">TindahanLedger</span>
        </a>

        <nav class="owner-navigation" aria-label="Owner navigation">
            <a class="owner-nav-link is-current" href="{{ route('dashboard') }}" aria-current="page">Dashboard</a>
            <a class="owner-nav-link" href="{{ route('overdue-accounts.index') }}">Overdue Accounts</a>
            <a class="owner-nav-link" href="{{ route('email-settings.edit') }}">Email Notifications</a>
            <a class="owner-nav-link" href="{{ route('late-penalties.edit') }}">Late Payment Policy</a>
            <a class="owner-nav-link" href="{{ route('password.edit') }}">Change Password</a>
            <span class="owner-name">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="logout-button" type="submit">Log out</button>
            </form>
        </nav>
    </header>

    <main class="dashboard-content">
        <header class="dashboard-heading">
            <p class="eyebrow">STORE OVERVIEW</p>
            <h1>Dashboard</h1>
            <p>Current balances and recent ledger activity.</p>
        </header>

        @if (session('status'))
            <p class="status-banner" role="status">{{ session('status') }}</p>
        @endif

        @if ($storeIsSuspended)
            <p class="status-banner" role="alert">
                {{ $errors->first('store') ?: 'This store is suspended. Records are read-only until an administrator reactivates it.' }}
            </p>
        @endif

        <section class="dashboard-metrics has-financials" aria-label="Store summary">
            <article class="metric-panel metric-collectibles" aria-labelledby="principal-title">
                <div class="metric-label-row">
                    <h2 id="principal-title">Outstanding principal</h2>
                    <span class="metric-symbol" aria-hidden="true">₱</span>
                </div>
                <p class="metric-value">₱{{ $outstandingPrincipal }}</p>
                <p class="metric-note">Unpaid credit, excluding late fees</p>
            </article>

            <article class="metric-panel metric-penalties" aria-labelledby="penalties-title">
                <div class="metric-label-row">
                    <h2 id="penalties-title">Outstanding late fees</h2>
                    <span class="metric-symbol" aria-hidden="true">₱</span>
                </div>
                <p class="metric-value">₱{{ $outstandingPenalties }}</p>
                <p class="metric-note">{{ $customersWithOutstandingPenalties }} {{ $customersWithOutstandingPenalties === 1 ? 'customer has' : 'customers have' }} an unpaid late fee</p>
                @if ($recentPenaltyCount > 0)
                    <p class="metric-note" role="status">{{ $recentPenaltyCount }} late payment {{ $recentPenaltyCount === 1 ? 'fee was' : 'fees were' }} added in the last 7 days.</p>
                @endif
            </article>

            <article class="metric-panel metric-collectibles" aria-labelledby="total-due-title">
                <div class="metric-label-row">
                    <h2 id="total-due-title">Total amount due</h2>
                    <span class="metric-symbol" aria-hidden="true">₱</span>
                </div>
                <p class="metric-value">₱{{ $totalAmountDue }}</p>
                <p class="metric-note">Outstanding principal plus outstanding late fees</p>
            </article>

            <article class="metric-panel metric-overdue" aria-labelledby="overdue-title">
                <div class="metric-label-row">
                    <h2 id="overdue-title">Overdue accounts</h2>
                    <span class="overdue-mark" aria-hidden="true"></span>
                </div>
                <p class="metric-value">{{ $overdueAccountCount }}</p>
                <a class="metric-note overdue-metric-link" href="{{ route('overdue-accounts.index') }}">Review overdue customers</a>
                <ul class="metric-note metric-penalty-counts" aria-label="Overdue credits by late fee status">
                    <li>{{ $overdueCreditPenaltyStates['in_grace'] }} overdue {{ $overdueCreditPenaltyStates['in_grace'] === 1 ? 'credit' : 'credits' }} still in the grace period</li>
                    <li>{{ $overdueCreditPenaltyStates['eligible'] }} eligible for a late fee at the next daily run</li>
                    <li>{{ $overdueCreditPenaltyStates['applied'] }} with a late fee already added</li>
                </ul>
            </article>
        </section>

        <section class="dashboard-customer-actions" aria-labelledby="customer-actions-title">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">CUSTOMERS</p>
                    <h2 id="customer-actions-title">Customer records</h2>
                </div>
            </div>
            <div class="dashboard-action-links">
                <a class="dashboard-action-link" href="{{ route('customers.create') }}">
                    <span class="action-mark" aria-hidden="true">+</span>
                    <span><strong>Add customer</strong><small>Create a customer ledger profile</small></span>
                </a>
                <a class="dashboard-action-link" href="{{ route('customers.index') }}">
                    <span class="action-mark action-mark-neutral" aria-hidden="true">≡</span>
                    <span><strong>Customer list</strong><small>Review and update customer details</small></span>
                </a>
            </div>
        </section>

        <section class="activity-section" aria-labelledby="activity-title">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">LEDGER</p>
                    <h2 id="activity-title">Recent activity</h2>
                </div>
                <span class="activity-limit">Latest 8 records</span>
            </div>

            @if ($recentActivity->isEmpty())
                <div class="activity-empty" role="status">
                    <span class="empty-mark" aria-hidden="true">—</span>
                    <p>No recent activity yet.</p>
                </div>
            @else
                <ol class="activity-list">
                    @foreach ($recentActivity as $activity)
                        @php($isPayment = ! in_array($activity['type'], ['Credit recorded', 'Late payment fee'], true))
                        <li class="activity-row">
                            <span class="activity-indicator {{ $isPayment ? 'is-payment' : 'is-credit' }}" aria-hidden="true">
                                {{ $isPayment ? '−' : '+' }}
                            </span>
                            <div class="activity-description">
                                <strong>{{ $activity['customer_name'] }}</strong>
                                <span>{{ $activity['type'] }}</span>
                            </div>
                            <time class="activity-date" datetime="{{ $activity['created_at']->toIso8601String() }}">
                                {{ $activity['created_at']->format('M j, g:i A') }}
                            </time>
                            <span class="activity-amount {{ $isPayment ? 'is-payment' : 'is-credit' }}">
                                {{ $isPayment ? '−' : '+' }}₱{{ $activity['amount'] }}
                            </span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        <style>
        .chatbot-toggle {
            position: fixed;
            right: 24px;
            bottom: 24px;
            background: #166534;
            color: white;
            border: none;
            border-radius: 30px;
            padding: 14px 20px;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
            box-shadow: 0 4px 14px #0002;
            z-index: 1000;
        }

        .chatbot-toggle:hover {
            background: #14532d;
        }

        .chatbot-panel {
            display: none;
            position: fixed;
            right: 24px;
            bottom: 85px;
            width: 340px;
            max-width: calc(100vw - 32px);
            height: 440px;
            max-height: calc(100vh - 120px);
            background: white;
            border-radius: 14px;
            box-shadow: 0 6px 25px #0003;
            overflow: hidden;
            z-index: 1001;
            flex-direction: column;
            font-family: Arial, sans-serif;
        }

        .chatbot-panel.open {
            display: flex;
        }

        .chatbot-header {
            background: #166534;
            color: white;
            padding: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .chatbot-header strong {
            font-size: 16px;
        }

        .chatbot-header button {
            color: white;
            background: transparent;
            border: none;
            font-size: 23px;
            cursor: pointer;
        }

        .chatbot-messages {
            flex: 1;
            padding: 14px;
            overflow-y: auto;
            background: #f8faf8;
        }

        .chat-message {
            padding: 10px 12px;
            margin-bottom: 10px;
            border-radius: 12px;
            max-width: 85%;
            font-size: 14px;
            line-height: 1.5;
            overflow-wrap: anywhere;
            white-space: pre-line;
        }

        .chat-message.bot {
            background: #e5e7eb;
            color: #1f2937;
            margin-right: auto;
        }

        .chat-message.user {
            background: #166534;
            color: white;
            margin-left: auto;
        }

        .chatbot-form {
            display: flex;
            gap: 8px;
            padding: 12px;
            border-top: 1px solid #e5e7eb;
        }

        .chatbot-form input {
            flex: 1;
            min-width: 0;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
        }

        .chatbot-form button {
            background: #166534;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 13px;
            cursor: pointer;
        }

        .chatbot-form button:disabled {
            opacity: 0.6;
            cursor: wait;
        }

        .faq-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            padding: 10px;
        }

        .faq-button {
            background: #ffffff;
            color: #176638;
            border: 1px solid #176638;
            border-radius: 18px;
            padding: 7px 10px;
            font-size: 11px;
            cursor: pointer;
        }

        .faq-button:hover {
            background: #176638;
            color: #ffffff;
        }

    </style>

    <button
        type="button"
        class="chatbot-toggle"
        id="chatbot-toggle"
        aria-expanded="false"
        aria-controls="chatbot-panel"
    >
        💬 Ask AteTindera
    </button>

    <section
        class="chatbot-panel"
        id="chatbot-panel"
        aria-label="TindahanLedger chatbot"
        aria-hidden="true"
    >
        <header class="chatbot-header">
            <strong>🛍️ AteTindera</strong>
            <button type="button" id="chatbot-close" aria-label="Close chat">×</button>
        </header>

        
    <div class="chatbot-messages" id="chatbot-messages" aria-live="polite">
        <div class="chat-message bot">
            Hello! I am AteTindera. I can help you with customer balances, overdue accounts, and recorded payments. What would you like to know?
        </div>
    </div>

    
<div class="faq-buttons">
    <button type="button" class="faq-button"
        data-question="How many customers are in the database?">
        Customer Accounts
    </button>

    <button type="button" class="faq-button"
        data-question="How many accounts are overdue?">
        Overdue Accounts
    </button>

    <button type="button" class="faq-button"
        data-question="What is the total outstanding balance of all customers?">
        Outstanding Balance
    </button>

    <button type="button" class="faq-button"
        data-question="What are today's transactions?">
        Today's Transactions
    </button>
</div>


        <form class="chatbot-form" id="chatbot-form">
            <input
                type="text"
                id="chatbot-input"
                placeholder="Type your question..."
                maxlength="1000"
                autocomplete="off"
                required
            >
            <button type="submit" id="chatbot-send">Send</button>
        </form>
    </section>

    <script>
        const toggleButton = document.getElementById('chatbot-toggle');
        const chatPanel = document.getElementById('chatbot-panel');
        const closeButton = document.getElementById('chatbot-close');
        const chatForm = document.getElementById('chatbot-form');
        const chatInput = document.getElementById('chatbot-input');
        const chatMessages = document.getElementById('chatbot-messages');
        const sendButton = document.getElementById('chatbot-send');

        function toggleChat(open) {
            chatPanel.classList.toggle('open', open);
            chatPanel.setAttribute('aria-hidden', String(!open));
            toggleButton.setAttribute('aria-expanded', String(open));

            if (open) {
                chatInput.focus();
            }
        }

        toggleButton.addEventListener('click', () => {
            toggleChat(!chatPanel.classList.contains('open'));
        });

        closeButton.addEventListener('click', () => toggleChat(false));

        function addMessage(message, sender) {
            const bubble = document.createElement('div');
            bubble.className = 'chat-message ' + sender;
            bubble.textContent = message;
            chatMessages.appendChild(bubble);
            chatMessages.scrollTop = chatMessages.scrollHeight;
            return bubble;
        }

        chatForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const message = chatInput.value.trim();

            if (!message) return;

            addMessage(message, 'user');
            chatInput.value = '';
            sendButton.disabled = true;

            const loadingMessage = addMessage('Thinking...', 'bot');

            try {
                const response = await fetch("{{ route('chatbot.chat') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ message: message })
                });

                if (!response.ok) {
                    throw new Error('Request failed: ' + response.status);
                }

                const data = await response.json();
                loadingMessage.textContent = data.reply || 'Sorry, I could not respond.';
            } catch (error) {
                loadingMessage.textContent =
                    'Sorry, something went wrong. Please try again.';
                console.error('Chatbot error:', error);
            } finally {
                sendButton.disabled = false;
                chatInput.focus();
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
        });

        document.querySelectorAll('.faq-button').forEach(function(button) {
            button.addEventListener('click', function() {
                chatInput.value = button.dataset.question;
                chatForm.requestSubmit();
            });
        });

    </script>
</body>
</html>
