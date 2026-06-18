<tr<% if $HideOnMobile %> class="hide-mobile"<% end_if %>>
    <td style="$InlineStyle">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <% loop $SortedImages %>
                    <td class="nl-col" width="{$Up.ColumnWidthPercent}%" valign="top" align="center" style="width:{$Up.ColumnWidthPercent}%;padding:4px;">
                        <img src="$AbsoluteURL" alt="$Title.ATT" style="display:block;border:0;outline:none;text-decoration:none;max-width:100%;height:auto;margin:0 auto;" />
                    </td>
                <% end_loop %>
            </tr>
        </table>
    </td>
</tr>
