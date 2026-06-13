<?php
/**
 * ROUTER NOTES — ID-W3
 * DO NOT EDIT router.php — sirf yahan se Master ko handoff karo
 * Master merge karega.
 *
 * ─────────────────────────────────────────────────────────────
 * t39 — LTCG/STCG Tax Report (StocksTaxReport)
 * ─────────────────────────────────────────────────────────────
 * GET    /api/stocks/tax-report                   -> StocksTaxReport::getTaxReport()
 * GET    /api/stocks/transactions                 -> StocksTaxReport::getTransactions()
 * POST   /api/stocks/transactions                 -> StocksTaxReport::addTransaction()
 * PUT    /api/stocks/transactions/{id}            -> StocksTaxReport::updateTransaction($id)
 * DELETE /api/stocks/transactions/{id}            -> StocksTaxReport::deleteTransaction($id)
 * POST   /api/stocks/transactions/bulk-import     -> StocksTaxReport::bulkImport()
 *
 * ─────────────────────────────────────────────────────────────
 * t114 — Gold Tracker (GoldTracker)
 * ─────────────────────────────────────────────────────────────
 * GET    /api/gold                                -> GoldTracker::getHoldings()
 * POST   /api/gold                                -> GoldTracker::addHolding()
 * PUT    /api/gold/{id}                           -> GoldTracker::updateHolding($id)
 * DELETE /api/gold/{id}                           -> GoldTracker::deleteHolding($id)
 * GET    /api/gold/summary                        -> GoldTracker::getSummary()
 * GET    /api/gold/price                          -> GoldTracker::getLivePrice()
 * POST   /api/gold/{id}/transaction               -> GoldTracker::addTransaction($id)
 *
 * ─────────────────────────────────────────────────────────────
 * t116 — Corporate Bonds / NCDs (BondsTracker)
 * ─────────────────────────────────────────────────────────────
 * GET    /api/bonds                               -> BondsTracker::getHoldings()
 * POST   /api/bonds                               -> BondsTracker::addHolding()
 * PUT    /api/bonds/{id}                          -> BondsTracker::updateHolding($id)
 * DELETE /api/bonds/{id}                          -> BondsTracker::deleteHolding($id)
 * GET    /api/bonds/summary                       -> BondsTracker::getSummary()
 * GET    /api/bonds/{id}/cashflows                -> BondsTracker::getCashflows($id)
 * POST   /api/bonds/{id}/cashflows                -> BondsTracker::generateCashflows($id)
 * PUT    /api/bonds/cashflows/{id}                -> BondsTracker::markReceived($id)
 * GET    /api/bonds/upcoming                      -> BondsTracker::getUpcoming()
 *
 * ─────────────────────────────────────────────────────────────
 * t121 — International Stocks / LRS (InternationalStocks)
 * ─────────────────────────────────────────────────────────────
 * GET    /api/international                       -> InternationalStocks::getHoldings()
 * POST   /api/international                       -> InternationalStocks::addHolding()
 * PUT    /api/international/{id}                  -> InternationalStocks::updateHolding($id)
 * DELETE /api/international/{id}                  -> InternationalStocks::deleteHolding($id)
 * GET    /api/international/summary               -> InternationalStocks::getSummary()
 * POST   /api/international/{id}/transaction      -> InternationalStocks::addTransaction($id)
 * GET    /api/international/lrs                   -> InternationalStocks::getLRS()
 * POST   /api/international/lrs                   -> InternationalStocks::addLRS()
 * DELETE /api/international/lrs/{id}              -> InternationalStocks::deleteLRS($id)
 * POST   /api/international/refresh-prices        -> InternationalStocks::refreshPrices()
 *
 * ─────────────────────────────────────────────────────────────
 * t145 — Reality Check (StockPickerRealityCheck)
 * ─────────────────────────────────────────────────────────────
 * GET    /api/stocks/reality-check                -> StockPickerRealityCheck::getRealityCheck()
 * GET    /api/stocks/alpha                        -> StockPickerRealityCheck::getAlphaReport()
 * POST   /api/stocks/snapshot                     -> StockPickerRealityCheck::takeSnapshot()
 * GET    /api/stocks/snapshot-history             -> StockPickerRealityCheck::getSnapshotHistory()
 *
 * ─────────────────────────────────────────────────────────────
 * t432 — Portfolio P/E (PortfolioPE)
 * ─────────────────────────────────────────────────────────────
 * GET    /api/stocks/pe-analysis                  -> PortfolioPE::getPEAnalysis()
 * GET    /api/stocks/market-pe                    -> PortfolioPE::getMarketPE()
 * POST   /api/stocks/fundamentals-refresh         -> PortfolioPE::refreshFundamentals()
 *
 * ─────────────────────────────────────────────────────────────
 * t435 — Watchlist (WatchlistManager)
 * ─────────────────────────────────────────────────────────────
 * GET    /api/watchlist                           -> WatchlistManager::getWatchlist()
 * POST   /api/watchlist                           -> WatchlistManager::addToWatchlist()
 * PUT    /api/watchlist/{id}                      -> WatchlistManager::updateWatchlist($id)
 * DELETE /api/watchlist/{id}                      -> WatchlistManager::removeFromWatchlist($id)
 * POST   /api/watchlist/bulk-remove               -> WatchlistManager::bulkRemove()
 * GET    /api/watchlist/alerts                    -> WatchlistManager::getPriceAlerts()
 * POST   /api/watchlist/refresh                   -> WatchlistManager::refreshPrices()
 *
 * ─────────────────────────────────────────────────────────────
 * t436 — Stock SIP (StockSIP)
 * ─────────────────────────────────────────────────────────────
 * GET    /api/stocks/sip                          -> StockSIP::getSIPs()
 * POST   /api/stocks/sip                          -> StockSIP::createSIP()
 * PUT    /api/stocks/sip/{id}                     -> StockSIP::updateSIP($id)
 * DELETE /api/stocks/sip/{id}                     -> StockSIP::deleteSIP($id)
 * GET    /api/stocks/sip/{id}/installments        -> StockSIP::getInstallments($id)
 * POST   /api/stocks/sip/{id}/installments        -> StockSIP::recordInstallment($id)
 * GET    /api/stocks/sip/summary                  -> StockSIP::getSummary()
 * GET    /api/stocks/sip/due-today                -> StockSIP::getDueToday()
 *
 * ─────────────────────────────────────────────────────────────
 * t38 — Screener (StocksScreener)
 * ─────────────────────────────────────────────────────────────
 * GET    /api/screener                            -> StocksScreener::screen()
 * GET    /api/screener/filters                    -> StocksScreener::getSavedFilters()
 * POST   /api/screener/filters                    -> StocksScreener::saveFilter()
 * DELETE /api/screener/filters/{id}               -> StocksScreener::deleteFilter($id)
 * POST   /api/screener/universe/refresh           -> StocksScreener::refreshUniverse()
 *
 * ─────────────────────────────────────────────────────────────
 * t118 — RBI / G-Secs / T-Bills (RBISecurities)
 * ─────────────────────────────────────────────────────────────
 * GET    /api/rbi                                 -> RBISecurities::getHoldings()
 * POST   /api/rbi                                 -> RBISecurities::addHolding()
 * PUT    /api/rbi/{id}                            -> RBISecurities::updateHolding($id)
 * DELETE /api/rbi/{id}                            -> RBISecurities::deleteHolding($id)
 * GET    /api/rbi/summary                         -> RBISecurities::getSummary()
 * GET    /api/rbi/{id}/cashflows                  -> RBISecurities::getCashflows($id)
 * PUT    /api/rbi/cashflows/{id}                  -> RBISecurities::markReceived($id)
 * POST   /api/rbi/{id}/floating-rate              -> RBISecurities::updateFloatingRate($id)
 * GET    /api/rbi/upcoming                        -> RBISecurities::getUpcoming()
 */
