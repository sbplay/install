/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */
var __importDefault=this&&this.__importDefault||function(e){return e&&e.__esModule?e:{default:e}};define(["require","exports","jquery","../AbstractInteractableModule","TYPO3/CMS/Backend/Notification","TYPO3/CMS/Core/Ajax/AjaxRequest","../../Router","TYPO3/CMS/Core/Event/DebounceEvent","bootstrap","../../Renderable/Clearable"],(function(e,t,s,a,n,o,l,i){"use strict";s=__importDefault(s);class r extends a.AbstractInteractableModule{constructor(){super(...arguments),this.selectorFulltextSearch=".t3js-upgradeDocs-fulltext-search",this.selectorChosenField=".t3js-upgradeDocs-chosen-select",this.selectorChangeLogsForVersionContainer=".t3js-version-changes",this.selectorChangeLogsForVersion=".t3js-changelog-list",this.selectorUpgradeDoc=".t3js-upgrade-doc"}static trimExplodeAndUnique(e,t){const a=[],n=t.split(e);for(let e=0;e<n.length;e++){const t=n[e].trim();t.length>0&&-1===s.default.inArray(t,a)&&a.push(t)}return a}initialize(t){this.currentModal=t,window.location!==window.parent.location?top.require(["TYPO3/CMS/Install/chosen.jquery.min"],()=>{this.getContent()}):e(["TYPO3/CMS/Install/chosen.jquery.min"],()=>{this.getContent()}),t.on("click",".t3js-upgradeDocs-markRead",e=>{this.markRead(e.target)}),t.on("click",".t3js-upgradeDocs-unmarkRead",e=>{this.unmarkRead(e.target)}),jQuery.expr[":"].contains=jQuery.expr.createPseudo(e=>t=>jQuery(t).text().toUpperCase().includes(e.toUpperCase()))}getContent(){const e=this.getModalBody();e.on("show.bs.collapse",this.selectorUpgradeDoc,e=>{this.renderTags(s.default(e.currentTarget))}),new o(l.getUrl("upgradeDocsGetContent")).get({cache:"no-cache"}).then(async t=>{const s=await t.resolve();!0===s.success&&"undefined"!==s.html&&s.html.length>0&&(e.empty().append(s.html),this.initializeFullTextSearch(),this.initializeChosenSelector(),this.loadChangelogs())},t=>{l.handleAjaxError(t,e)})}loadChangelogs(){const e=[],t=this.getModalBody();this.findInModal(this.selectorChangeLogsForVersionContainer).each((a,i)=>{const r=new o(l.getUrl("upgradeDocsGetChangelogForVersion")).withQueryArguments({install:{version:i.dataset.version}}).get({cache:"no-cache"}).then(async e=>{const t=await e.resolve();if(!0===t.success){const e=s.default(i),a=e.find(this.selectorChangeLogsForVersion);a.html(t.html),this.moveNotRelevantDocuments(a),e.find(".t3js-panel-loading").remove()}else n.error("Something went wrong","The request was not processed successfully. Please check the browser's console and TYPO3's log.")},e=>{l.handleAjaxError(e,t)});e.push(r)}),Promise.all(e).then(()=>{this.fulltextSearchField.prop("disabled",!1),this.appendItemsToChosenSelector()})}initializeFullTextSearch(){this.fulltextSearchField=this.findInModal(this.selectorFulltextSearch);const e=this.fulltextSearchField.get(0);e.clearable({onClear:()=>{this.combinedFilterSearch()}}),e.focus(),this.initializeChosenSelector(),new i("keyup",()=>{this.combinedFilterSearch()}).bindTo(e)}initializeChosenSelector(){this.chosenField=this.getModalBody().find(this.selectorChosenField);const e={".chosen-select":{width:"100%",placeholder_text_multiple:"tags"},".chosen-select-deselect":{allow_single_deselect:!0},".chosen-select-no-single":{disable_search_threshold:10},".chosen-select-no-results":{no_results_text:"Oops, nothing found!"},".chosen-select-width":{width:"100%"}};for(const t in e)e.hasOwnProperty(t)&&this.findInModal(t).chosen(e[t]);this.chosenField.on("change",()=>{this.combinedFilterSearch()})}appendItemsToChosenSelector(){let e="";s.default(this.findInModal(this.selectorUpgradeDoc)).each((t,a)=>{e+=s.default(a).data("item-tags")+","});const t=r.trimExplodeAndUnique(",",e).sort((e,t)=>e.toLowerCase().localeCompare(t.toLowerCase()));this.chosenField.prop("disabled",!1),s.default.each(t,(e,t)=>{this.chosenField.append(s.default("<option>").text(t))}),this.chosenField.trigger("chosen:updated")}combinedFilterSearch(){const e=this.getModalBody(),t=e.find("div.item");if(this.chosenField.val().length<1&&this.fulltextSearchField.val().length<1)return this.currentModal.find(".panel-version .panel-collapse.in").collapse("hide"),t.removeClass("hidden searchhit filterhit"),!1;if(t.addClass("hidden").removeClass("searchhit filterhit"),this.chosenField.val().length>0){t.addClass("hidden").removeClass("filterhit");const a=[],n=[];s.default.each(this.chosenField.val(),(e,t)=>{const s='[data-item-tags*="'+t+'"]';t.includes(":",1)?a.push(s):n.push(s)});const o=n.join(""),l=[];if(a.length)for(let e of a)l.push(o+e);else l.push(o);const i=l.join(",");e.find(i).removeClass("hidden").addClass("searchhit filterhit")}else t.addClass("filterhit").removeClass("hidden");const a=this.fulltextSearchField.val();return e.find("div.item.filterhit").each((e,t)=>{const n=s.default(t);s.default(":contains("+a+")",n).length>0||s.default('input[value*="'+a+'"]',n).length>0?n.removeClass("hidden").addClass("searchhit"):n.removeClass("searchhit").addClass("hidden")}),e.find(".searchhit").closest(".panel-collapse").collapse("show"),e.find(".panel-version").each((e,t)=>{const a=s.default(t);a.find(".searchhit",".filterhit").length<1&&a.find(" > .panel-collapse").collapse("hide")}),!0}renderTags(e){const t=e.find(".t3js-tags");if(0===t.children().length){e.data("item-tags").split(",").forEach(e=>{t.append(s.default("<span />",{class:"label"}).text(e))})}}moveNotRelevantDocuments(e){e.find('[data-item-state="read"]').appendTo(this.findInModal(".panel-body-read")),e.find('[data-item-state="notAffected"]').appendTo(this.findInModal(".panel-body-not-affected"))}markRead(e){const t=this.getModalBody(),a=this.getModuleContent().data("upgrade-docs-mark-read-token"),n=s.default(e).closest("a");n.toggleClass("t3js-upgradeDocs-unmarkRead t3js-upgradeDocs-markRead"),n.find("i").toggleClass("fa-check fa-ban"),n.closest(".panel").appendTo(this.findInModal(".panel-body-read")),new o(l.getUrl()).post({install:{ignoreFile:n.data("filepath"),token:a,action:"upgradeDocsMarkRead"}}).catch(e=>{l.handleAjaxError(e,t)})}unmarkRead(e){const t=this.getModalBody(),a=this.getModuleContent().data("upgrade-docs-unmark-read-token"),n=s.default(e).closest("a"),i=n.closest(".panel").data("item-version");n.toggleClass("t3js-upgradeDocs-markRead t3js-upgradeDocs-unmarkRead"),n.find("i").toggleClass("fa-check fa-ban"),n.closest(".panel").appendTo(this.findInModal('*[data-group-version="'+i+'"] .panel-body')),new o(l.getUrl()).post({install:{ignoreFile:n.data("filepath"),token:a,action:"upgradeDocsUnmarkRead"}}).catch(e=>{l.handleAjaxError(e,t)})}}return new r}));