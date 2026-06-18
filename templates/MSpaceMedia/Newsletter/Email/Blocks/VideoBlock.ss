<tr<% if $HideOnMobile %> class="hide-mobile"<% end_if %>>
    <td style="$InlineStyle" align="$safeAlignment">
        <a href="$VideoURL.ATT" target="_blank" style="text-decoration:none;">
            <% with $ScaledThumbnail %>
                <img src="$AbsoluteURL" alt="$Up.Alt.ATT" width="$Width" style="display:block;border:0;outline:none;text-decoration:none;max-width:100%;height:auto;<% if $Up.Alignment == center %>margin:0 auto;<% end_if %>" />
            <% end_with %>
        </a>
    </td>
</tr>
