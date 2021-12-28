jQuery(function($) {

	/////
	// define consts

	const images_data = $('.parrotposter--images .parrotposter-input__items').data('items')
	const conditions_data = $('.parrotposter--conditions .parrotposter-input__items').data('items')
	const wp_post_type_val = $('select[name="parrotposter[wp_post_type]"]').val()

	/////
	// define functions

	function checkTagsEnable() {
		const enabled = $('input[name="parrotposter[utm_enable]"]').is(':checked')
		if (!enabled) {
			$('.parrotposter--utm-param').hide()
		} else {
			$('.parrotposter--utm-param').show()
		}
	}

	function checkPublishTime() {
		const value = $('select[name="parrotposter[when_publish]"]').val()
		if (value == 'delay') {
			$('.parrotposter--publish-delay').show()
		} else {
			$('.parrotposter--publish-delay').hide()
		}
	}

	function insertPostField (textarea, key) {
		const istart = textarea.selectionStart;
		const iend = textarea.selectionEnd;
		const itxt = textarea.value;
		textarea.value = itxt.substr(0, istart) + key + itxt.substr(iend);
		textarea.focus();
		const cursor = key.length + istart;
		textarea.setSelectionRange(cursor, cursor);
	}

	function updateImages() {
		if (!images_data.selected_items.length) {
			addImageField(images_data.available_items, '')
			return
		}

		images_data.selected_items.forEach(function(key) {
			addImageField(images_data.available_items, key)
		})
	}

	function addImageField(items, selected) {
		const selectElem = $('<select name="parrotposter[post_images][]">')
		items.forEach(function(item) {
			const option = $('<option value=""></option>')
			option.attr('value', item.key)
			option.text(item.label)
			if (item.key == selected) {
				option.attr('selected', 'selected')
			}
			selectElem.append(option)
		})

		const removeBtn = $('<span class="parrotposter-input__remove-btn"> X </span>')
		removeBtn.click(removeImageField)

		const elem = $('<div class="parrotposter-input__item"></div>')
		elem.append(selectElem)
		elem.append(removeBtn)

		const container = $('.parrotposter--images .parrotposter-input__items')
		container.append(elem)
	}

	function removeImageField(e) {
		e.preventDefault()
		$(e.target).parent().remove()

		const items = $('.parrotposter--images .parrotposter-input__items').children()
		if (!items.length) {
			addImageField(images_data.available_items, '')
		}
	}

	function updateConditions() {
		let len = conditions_data.selected_items.length
		if (len == undefined) {
			len = Object.keys(conditions_data.selected_items).length
		}

		let inited = 0
		$.each(conditions_data.selected_items, function(i, selected) {
			const res = addConditionField(conditions_data.available_items, selected)
			if (res === false) {
				return
			}
			inited++
		})

		if (inited == 0) {
			addConditionField(conditions_data.available_items, null)
			return
		}
	}

	function addConditionField(items, selected) {
		if (selected == null) {
			selected = {}
		} else if (!selected.key || !selected.key.length) {
			return false
		} else if (selected.key) {
			const cond = findCondition(items, selected.key)
			if (!cond) {
				return false
			}
		}

		const container = $('.parrotposter--conditions .parrotposter-input__items')

		let idx = container.data('idx')
		if (idx == undefined) {
			idx = 0
		} else {
			idx++
		}
		container.data('idx', idx)

		const keyElem = $(`<select class="parrotposter--condition-key" name="parrotposter[conditions][${idx}][key]">`)
		keyElem.change(conditionKeyChanged)
		items.forEach(function(item) {
			const option = $('<option value=""></option>')
			option.attr('value', item.key)
			option.text(item.label)
			if (item.key == selected.key) {
				option.attr('selected', 'selected')
			}
			keyElem.append(option)
		})

		const removeBtn = $('<span class="parrotposter-input__remove-btn"> X </span>')
		removeBtn.click(removeConditionField)

		const elem = $('<div class="parrotposter-input__item"></div>')
		elem.data('idx', idx)
		elem.append(keyElem)
		elem.append(getConditionOpElem(items, selected, idx))
		elem.append(getConditionValueElem(items, selected, idx))
		elem.append(removeBtn)

		container.append(elem)

		updateMultiselect(elem.find('select[multiple]'))
	}

	function removeConditionField(e) {
		e.preventDefault()
		$(e.target).parent().remove()

		const items = $('.parrotposter--conditions .parrotposter-input__items').children()
		if (!items.length) {
			addConditionField(conditions_data.available_items, null)
		}
	}

	function updateMultiselect(elem) {
		$(elem).pqSelect({
			multiplePlaceholder: wp.i18n.__('Select value(s)', 'parrotposter'),
			displayText: wp.i18n.__('{ 0 } of { 1 } selected', 'parrotposter'),
			selectallText: '',
			maxDisplay: 7,
			checkbox: true,
		})
	}

	function getConditionOpElem(items, selected, idx) {
		const opElem = $(`<select class="parrotposter--condition-op" name="parrotposter[conditions][${idx}][op]">`)

		const cond = findCondition(items, selected.key)
		if (!cond) {
			return opElem
		}

		$.each(cond.ops, function(op, opLabel) {
			const option = $('<option value=""></option>')
			option.attr('value', op)
			option.text(opLabel)
			if (op == selected.op) {
				option.attr('selected', 'selected')
			}
			opElem.append(option)
		})

		return opElem
	}

	function getConditionValueElem(items, selected, idx) {
		let inputType = 'text'
		function valueElemTpl() {
			return $(`<input type="${inputType}" class="parrotposter--condition-value" name="parrotposter[conditions][${idx}][value]">`)
		}

		const cond = findCondition(items, selected.key)
		if (!cond) {
			return valueElemTpl()
		}


		let valueElem;
		if (cond.input == 'text' || cond.input == 'number') {

			inputType = cond.input
			valueElem = valueElemTpl()
			valueElem.val(selected.value || '')

		} else if (cond.input == 'select') {

			valueElem = $(`<select class="parrotposter--condition-value" name="parrotposter[conditions][${idx}][value]">`)
			if (cond.multi) {
				valueElem.attr('multiple', 'multiple')
				valueElem.attr('name', valueElem.attr('name') + '[]')
			}
			valueElem.attr('size', cond.values.length < 6 ? cond.values.length : 6)

			$.each(cond.values, function(i, item) {
				const option = $('<option value=""></option>')
				option.attr('value', item.key)
				option.text(item.label)
				if ((cond.multi && inArray(item.key, selected.value) > -1) || item.key == selected.value) {
					option.attr('selected', 'selected')
				}
				valueElem.append(option)
			})
		}

		return valueElem
	}

	function findCondition(items, key) {
		const index = items.map(function(el) {
			return el.key
		}).indexOf(key)
		return index >= 0 ? items[index] : null
	}

	function conditionKeyChanged(e) {
		e.preventDefault()
		const elem = $(e.target)
		const idx = elem.parent().data('idx')

		const selected = {
			key: elem.val(),
		}
		let opElem = getConditionOpElem(conditions_data.available_items, selected, idx)
		elem.parent().find('.parrotposter--condition-op').replaceWith(opElem)
		let valueElem = getConditionValueElem(conditions_data.available_items, selected, idx)
		elem.parent().find('.parrotposter--condition-value').replaceWith(valueElem)
		updateMultiselect(elem.parent().find('select[multiple].parrotposter--condition-value'))
	}

	function wpPostTypeChanged(e) {
		const elem = $(e.target)
		const val = elem.val()
		elem.parent().find('.parrotposter--wp-post-type-changed').remove()
		if (wp_post_type_val == val) {
			return
		}

		const parent = elem.parent()
		parent.find('select').after($(`
			<div class="parrotposter--wp-post-type-changed">
				<button class="button" name="parrotposter[apply]" value="1" type="submit">${wp.i18n.__('Reload to apply change')}</button>
			</div>
		`))
	}

	/////
	// tools

	function inArray(elem, arr) {
		let idx = -1
		$.each(arr, function(i, v) {
			if (v == elem) {
				idx = i
				return false
			}
		})
		return idx
	}

	/////
	// initialize script

	checkTagsEnable()
	$('input[name="parrotposter[utm_enable]"]').change(checkTagsEnable)

	checkPublishTime()
	$('select[name="parrotposter[when_publish]"]').change(checkPublishTime)

	$('.parrotposter-autoposting-form__post-fields').click(function(e) {
		const elem = $(e.target).closest('.parrotposter-autoposting-form__post-field')
		const key = elem.data('key')
		const textarea = elem.closest('.parrotposter-autoposting-form__input-row').find('textarea')[0]

		insertPostField(textarea, key)
	})

	updateImages()
	$('.parrotposter--images .parrotposter-input__add-btn').click(function(e) {
		e.preventDefault()
		addImageField(images_data.available_items, '')
	})

	updateConditions()
	$('.parrotposter--conditions .parrotposter-input__add-btn').click(function(e) {
		e.preventDefault()
		addConditionField(conditions_data.available_items, null)
	})

	$('select[name="parrotposter[wp_post_type]"]').change(wpPostTypeChanged)

	// textarea auto resize
	$("textarea").each(function () {
		this.setAttribute("style", "height:" + (this.scrollHeight) + "px;overflow-y:hidden;");
	}).on("input", function () {
		this.style.height = "auto";
		this.style.height = (this.scrollHeight) + "px";
	});
})
