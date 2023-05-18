local ok, lsp = pcall(require, 'lsp-zero')
if ok then
	lsp.configure('intelephense', {
		settings = {
			intelephense = {
				environment = {
					includePaths = {
						"../../../wp-includes"
					}
				}
			},
		}
	})
end
